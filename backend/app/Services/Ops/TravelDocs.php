<?php

namespace App\Services\Ops;

use App\Exceptions\ApiException;
use App\Models\Admin\Setting;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\Passenger;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Destination;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDestination;
use App\Models\Catalog\ProductRequirement;
use App\Models\Iam\User;
use App\Models\Ops\TravelDocContact;
use App\Models\Ops\TravelDocItem;
use App\Models\Ops\TravelDocReminder;
use App\Models\Ops\TravelDocSpecial;
use App\Services\AuditLogger;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Documentos de viaje del pasajero (spec 11 §3.7–3.10, §4.4, DOC-BR-001..013): checklist por pasajero con aplicabilidad y
 * estados `vencido`/`no_aplica` calculados al leer, carga/validación/observación, contacto de emergencia, atención especial,
 * solicitudes al cliente y recordatorios (JOB-11).
 */
class TravelDocs
{
    public const DEFAULTS = [
        'reminder_days' => [15, 7, 3], 'channels' => ['Email', 'WhatsApp'], 'passport_months' => 6,
        'visa_nationalities' => ['China', 'India', 'Vietnamita'], 'insurance_trekking' => true,
        'clinical_terms' => ['diabetes', 'diabético', 'epilepsia', 'cáncer', 'vih', 'sida', 'hipertensión', 'asma', 'cardiopatía', 'depresión', 'ansiedad',
            'embarazo', 'embarazada', 'diagnóstico', 'tratamiento', 'medicamento', 'enfermedad', 'insulina', 'quimioterapia', 'psiquiátrico'],
    ];

    public const CHANNELS = ['Email', 'WhatsApp'];

    /** Estados de reserva que llevan checklist (confirmadas y en curso). */
    public const ACTIVE = [Reservation::CONFIRMED, Reservation::PENDING_PAYMENT, Reservation::PENDING_PROVIDER, Reservation::RESCHEDULED, Reservation::IN_OPERATION, 'parcial', 'incidencia'];

    public const NATIONAL = ['peruana', 'peruano', 'perú', 'peru', 'pe'];

    public const AMAZON = ['loreto', 'madre de dios'];

    public const MIME = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public const MAX_BYTES = 10 * 1024 * 1024;

    private const RANK = ['completo' => 1, 'pendiente' => 2, 'recibido' => 2, 'observado' => 3, 'vencido' => 4];

    /** @var array<string, array> */
    private array $contexts = [];

    private ?array $settings = null;

    /** Las cachés valen por solicitud: el router reutiliza el controlador (y este servicio) entre solicitudes del mismo proceso. */
    private ?int $scope = null;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
        private readonly CrmClock $clock,
        private readonly TenantContext $tenant,
    ) {}

    public static function register(): void
    {
        $run = fn (callable $fn) => app(TenantContext::class)->has() ? $fn(app(self::class)) : null;
        // `CreateTravelDocChecklist` (§8): ReservationConfirmed / PassengerUpdated.
        Reservation::updated(function (Reservation $r) use ($run) {
            if ($r->wasChanged('status') && in_array($r->status, self::ACTIVE, true)) {
                $run(fn (self $d) => $d->ensure(collect([$r])));
            }
        });
        Passenger::saved(function (Passenger $p) use ($run) {
            $r = Reservation::find($p->reservation_id);
            if ($r && in_array($r->status, self::ACTIVE, true)) {
                $run(fn (self $d) => $d->ensure(collect([$r])));
            }
        });
    }

    // ── Configuración (§3.10) ─────────────────────────────────────────────────────────────────

    private function fresh(): void
    {
        $scope = spl_object_id(request());
        if ($scope !== $this->scope) {
            [$this->scope, $this->settings, $this->contexts] = [$scope, null, []];
        }
    }

    public function settings(): array
    {
        $this->fresh();

        return $this->settings ??= array_merge(self::DEFAULTS, Setting::find('travel_docs')?->values ?? []);
    }

    public function saveSettings(array $data, User $actor): array
    {
        $current = $this->settings();
        $rawDays = $data['reminder_days'] ?? $current['reminder_days'];
        $days = collect(is_string($rawDays) ? preg_split('/[\s,;]+/', $rawDays, -1, PREG_SPLIT_NO_EMPTY) : $rawDays)->map(fn ($d) => (int) $d)->filter(fn ($d) => $d >= 1 && $d <= 90)->unique()->sortDesc()->values()->all();
        if ($days === []) {
            throw ApiException::invalid('reminder_days', 'Indique al menos un día de recordatorio (1 a 90).');
        }
        $channels = array_values(array_intersect(self::CHANNELS, (array) ($data['channels'] ?? $current['channels'])));
        if ($channels === []) {
            throw ApiException::invalid('channels', 'Elija al menos un canal.');
        }
        $months = (int) ($data['passport_months'] ?? $current['passport_months']);
        if ($months < 0 || $months > 24) {
            throw ApiException::invalid('passport_months', 'La vigencia mínima del pasaporte va de 0 a 24 meses.');
        }
        $list = fn ($v) => collect(is_string($v) ? explode(',', $v) : (array) $v)->map(fn ($x) => trim((string) $x))->filter()->unique()->values()->all();
        $values = [
            'reminder_days' => $days, 'channels' => $channels, 'passport_months' => $months,
            'visa_nationalities' => $list($data['visa_nationalities'] ?? $current['visa_nationalities']),
            'insurance_trekking' => filter_var($data['insurance_trekking'] ?? $current['insurance_trekking'], FILTER_VALIDATE_BOOLEAN),
            'clinical_terms' => array_map('mb_strtolower', $list($data['clinical_terms'] ?? $current['clinical_terms'])),
        ];
        $row = Setting::find('travel_docs');
        $before = json_encode(array_diff_key($current, ['clinical_terms' => 1]), JSON_UNESCAPED_UNICODE);
        if ($row) {
            $row->forceFill(['values' => $values, 'updated_by' => $actor->id, 'lock_version' => $row->lock_version + 1])->save();
        } else {
            Setting::create(['section' => 'travel_docs', 'values' => $values, 'updated_by' => $actor->id]);
        }
        $this->settings = null;
        $this->audit->log('Documentos de viaje', 'reglas', 'editar_reglas', $before, json_encode(array_diff_key($values, ['clinical_terms' => 1]), JSON_UNESCAPED_UNICODE), actor: $actor);

        return $this->settings();
    }

    // ── Checklist ─────────────────────────────────────────────────────────────────────────────

    /** Crea los 8 ítems por pasajero que falten (la aplicabilidad se calcula al leer). */
    public function ensure(Collection $reservations): void
    {
        $ids = $reservations->pluck('id')->filter()->all();
        if ($ids === []) {
            return;
        }
        $passengers = Passenger::whereIn('reservation_id', $ids)->get(['id', 'reservation_id']);
        $have = TravelDocItem::whereIn('reservation_id', $ids)->get(['passenger_id', 'doc_type'])->map(fn ($i) => "{$i->passenger_id}:{$i->doc_type}")->flip();
        $rows = [];
        foreach ($passengers as $p) {
            foreach (array_keys(TravelDocItem::TYPES) as $type) {
                if (! isset($have["{$p->id}:{$type}"])) {
                    $rows[] = ['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id(), 'reservation_id' => $p->reservation_id, 'passenger_id' => $p->id,
                        'doc_type' => $type, 'status' => 'pendiente', 'created_at' => now(), 'updated_at' => now()];
                }
            }
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            TravelDocItem::insertOrIgnore($chunk);
        }
    }

    /** Reservas con checklist: activas y con viaje no terminado. */
    public function upcoming(): Builder
    {
        return Reservation::whereIn('status', self::ACTIVE)->whereDate('travel_to', '>=', $this->today()->toDateString());
    }

    /** Datos del viaje que deciden la aplicabilidad: fechas, aventura/trekking, Amazonía y entradas MAPI. */
    public function context(Reservation $r): array
    {
        $this->fresh();
        if (isset($this->contexts[$r->id])) {
            return $this->contexts[$r->id];
        }
        $services = BookedService::with(['provider:id,name'])->where('reservation_id', $r->id)->get();
        $productIds = $services->pluck('product_id')->filter()->unique()->values();
        $products = Product::withTrashed()->with('category')->whereIn('id', $productIds)->get();
        $destIds = ProductDestination::whereIn('product_id', $productIds)->pluck('destination_id')->merge($products->pluck('primary_destination_id'))->filter()->unique();
        $paths = Destination::withTrashed()->whereIn('id', $destIds)->pluck('path')->implode('/');
        $ancestors = Destination::withTrashed()->whereIn('id', array_filter(explode('/', $paths)))->pluck('name')->map(fn ($n) => mb_strtolower($n));
        $requirements = ProductRequirement::whereIn('product_id', $productIds)->pluck('text')->implode(' ');
        $mapi = $services->filter(fn (BookedService $s) => $s->isActive() || $s->status === BookedService::COMPLETED)->filter(fn ($s) => $this->isMapi($s));

        return $this->contexts[$r->id] = [
            'from' => $r->travel_from, 'to' => $r->travel_to ?? $r->travel_from,
            'adventure' => $products->contains(fn ($p) => mb_strtolower((string) $p->category?->name) === 'aventura'),
            'amazon' => $ancestors->intersect(self::AMAZON)->isNotEmpty() || Str::contains(mb_strtolower($requirements), 'fiebre amarilla'),
            'mapi' => $mapi->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'date' => $s->service_date->toDateString()])->values()->all(),
        ];
    }

    /**
     * DOC-BR-010: entrada nominativa MAPI = servicio del Ministerio de Cultura o entrada de catálogo a Machu Picchu.
     * (El catálogo demo no tiene al Ministerio como proveedor: la entrada la gestiona un operador.)
     */
    public function isMapi(BookedService $s): bool
    {
        if (Str::contains(mb_strtolower((string) $s->provider?->name), 'ministerio de cultura')) {
            return true;
        }
        $type = $s->catalog_service_id ? DB::table('catalog_services')->where('id', $s->catalog_service_id)->value('type') : null;

        return $type === 'Entrada' && Str::contains(mb_strtolower($s->name), 'machu picchu');
    }

    public function isForeign(Passenger $p): bool
    {
        return $p->nationality !== null && trim($p->nationality) !== '' && ! in_array(mb_strtolower(trim($p->nationality)), self::NATIONAL, true);
    }

    public function isMinor(Passenger $p, ?Carbon $at): bool
    {
        if (in_array($p->pax_type, ['CHD', 'INF'], true)) {
            return true;
        }
        if ($p->birth_date && $at) {
            return $p->birth_date->diffInYears($at) < 18;
        }

        return $p->age !== null && $p->age < 18;
    }

    /** Vencimiento mínimo exigido del documento de identidad (DOC-BR-001/002). */
    public function minExpiry(Passenger $p, array $ctx): ?Carbon
    {
        $end = $ctx['to'] ? Carbon::parse($ctx['to'])->startOfDay() : null;

        return $end && $this->isForeign($p) ? $end->copy()->addMonths((int) $this->settings()['passport_months']) : $end;
    }

    /** @return array{0: bool, 1: ?string} [aplica, motivo si no aplica] */
    public function applies(string $type, Passenger $p, array $ctx, ?TravelDocSpecial $special): array
    {
        $s = $this->settings();
        $foreign = $this->isForeign($p);
        $nationality = $p->nationality ?: 'sin nacionalidad registrada';

        return match ($type) {
            'visa' => in_array(mb_strtolower((string) $p->nationality), array_map('mb_strtolower', $s['visa_nationalities']), true)
                ? [true, null] : [false, "Nacionalidad {$nationality}: no requiere visa"],
            'insurance' => $foreign || ($s['insurance_trekking'] && $ctx['adventure']) ? [true, null] : [false, 'Nacional en programa sin trekking ni altura'],
            'yellow_fever' => $ctx['amazon'] ? [true, null] : [false, 'El viaje no incluye destinos en la Amazonía'],
            'minor_auth' => $this->isMinor($p, $ctx['from'] ? Carbon::parse($ctx['from']) : null) ? [true, null] : [false, 'Mayor de edad'],
            'migration' => $foreign ? [true, null] : [false, 'Peruano: no requiere registro migratorio'],
            'special' => $special?->requires_attention ? [true, null] : [false, 'Sin atención especial declarada'],
            default => [true, null],
        };
    }

    /** Estado efectivo (§4.4): vencido y no_aplica se calculan; contacto y atención especial se completan con datos. */
    public function effective(TravelDocItem $i, Passenger $p, array $ctx, ?TravelDocContact $contact, ?TravelDocSpecial $special): array
    {
        [$applies, $reason] = $this->applies($i->doc_type, $p, $ctx, $special);
        if (! $applies) {
            return ['status' => 'no_aplica', 'applies' => false, 'reason' => $reason];
        }
        $status = match ($i->doc_type) {
            'emergency' => $contact ? 'completo' : 'pendiente',
            'special' => 'completo',
            default => $i->status,
        };
        $min = $i->doc_type === 'id_doc' ? $this->minExpiry($p, $ctx) : null;
        if ($min && $i->expiry_date && in_array($status, ['recibido', 'completo'], true) && $i->expiry_date->lt($min)) {
            return ['status' => 'vencido', 'applies' => true, 'reason' => "Vence el {$i->expiry_date->format('d/m/Y')}; se exige vigencia hasta el {$min->format('d/m/Y')}"];
        }

        return ['status' => $status, 'applies' => true, 'reason' => null];
    }

    public static function worst(array $statuses): string
    {
        $statuses = array_filter($statuses, fn ($s) => isset(self::RANK[$s]));
        if ($statuses === []) {
            return 'completo';
        }
        usort($statuses, fn ($a, $b) => self::RANK[$b] <=> self::RANK[$a]);

        return $statuses[0] === 'recibido' ? 'pendiente' : $statuses[0];
    }

    /** Checklist completo de la reserva (GET /reservations/{id}/travel-docs). */
    public function checklist(Reservation $r): array
    {
        $this->ensure(collect([$r]));
        $ctx = $this->context($r);
        $passengers = Passenger::where('reservation_id', $r->id)->orderBy('seq')->get();
        $items = TravelDocItem::with('validator:id,name')->where('reservation_id', $r->id)->get()->groupBy('passenger_id');
        $contacts = TravelDocContact::whereIn('passenger_id', $passengers->pluck('id'))->get()->keyBy('passenger_id');
        $specials = TravelDocSpecial::whereIn('passenger_id', $passengers->pluck('id'))->get()->keyBy('passenger_id');
        $rows = [];
        foreach ($passengers as $p) {
            $list = [];
            foreach (array_keys(TravelDocItem::TYPES) as $type) {
                $i = ($items[$p->id] ?? collect())->firstWhere('doc_type', $type);
                if (! $i) {
                    continue;
                }
                $e = $this->effective($i, $p, $ctx, $contacts[$p->id] ?? null, $specials[$p->id] ?? null);
                $min = $type === 'id_doc' ? $this->minExpiry($p, $ctx) : null;
                $list[] = ['id' => $i->id, 'type' => $type, 'label' => TravelDocItem::TYPES[$type], 'status' => $e['status'], 'status_label' => TravelDocItem::STATUS_LABELS[$e['status']],
                    'stored_status' => $i->status, 'applies' => $e['applies'], 'reason' => $e['reason'], 'doc_number' => $i->doc_number, 'expiry_date' => $i->expiry_date?->toDateString(),
                    'min_expiry' => $min?->toDateString(), 'file_name' => $i->file_name, 'file_mime' => $i->file_mime, 'file_size' => $i->file_size, 'has_file' => $i->storage_path !== null || $i->file_name !== null,
                    'uploaded_by_type' => $i->uploaded_by_type, 'uploaded_at' => $i->uploaded_at?->toIso8601String(), 'observation' => $i->observation,
                    'validated_by' => $i->validator?->name, 'validated_at' => $i->validated_at?->toIso8601String(), 'note' => $i->note, 'lock_version' => (int) $i->lock_version,
                    'data_type' => in_array($type, TravelDocItem::DATA_TYPES, true)];
            }
            $applicable = array_values(array_filter($list, fn ($x) => $x['applies']));
            $rows[] = ['id' => $p->id, 'seq' => $p->seq, 'name' => $p->full_name, 'pax_type' => $p->pax_type, 'nationality' => $p->nationality, 'doc_type' => $p->doc_type,
                'doc_number' => $p->doc_number, 'birth_date' => $p->birth_date?->toDateString(), 'is_lead' => (bool) $p->is_lead, 'foreign' => $this->isForeign($p),
                'minor' => $this->isMinor($p, $ctx['from'] ? Carbon::parse($ctx['from']) : null),
                'status' => self::worst(array_column($applicable, 'status')), 'items' => $applicable,
                'not_applicable' => array_values(array_map(fn ($x) => ['type' => $x['type'], 'label' => $x['label'], 'reason' => $x['reason']], array_filter($list, fn ($x) => ! $x['applies']))),
                'contact' => ($c = $contacts[$p->id] ?? null) ? ['name' => $c->name, 'phone' => $c->phone, 'relation' => $c->relation] : null,
                'special' => ($s = $specials[$p->id] ?? null) ? ['requires_attention' => $s->requires_attention, 'operational_note' => $s->operational_note] : null];
        }

        return ['passengers' => $rows, 'status' => self::worst(array_column($rows, 'status')), 'gate' => $this->gate($r), 'context' => $ctx];
    }

    /** Resumen por reserva para el tablero. */
    public function summary(Reservation $r): array
    {
        $c = $this->checklist($r);
        $items = collect($c['passengers'])->flatMap(fn ($p) => $p['items']);

        return [
            'status' => $c['status'], 'pax' => count($c['passengers']),
            'pax_pending' => collect($c['passengers'])->where('status', '<>', 'completo')->count(),
            'to_validate' => $items->where('status', 'recibido')->count(), 'observed' => $items->where('status', 'observado')->count(),
            'expired' => $items->where('status', 'vencido')->count(), 'pending' => $items->whereIn('status', ['pendiente', 'recibido', 'observado', 'vencido'])->count(),
            'gate' => $c['gate'], 'passengers' => $c['passengers'],
        ];
    }

    /** `TravelDocsGate` (DOC-BR-010): con entrada MAPI, cada pasajero necesita id_doc completo y, si es menor, autorización completa. */
    public function gate(Reservation $r): array
    {
        $ctx = $this->context($r);
        if ($ctx['mapi'] === []) {
            return ['applies' => false, 'blocked' => false, 'missing' => [], 'services' => []];
        }
        $this->ensure(collect([$r]));
        $passengers = Passenger::where('reservation_id', $r->id)->orderBy('seq')->get();
        $items = TravelDocItem::where('reservation_id', $r->id)->whereIn('doc_type', ['id_doc', 'minor_auth'])->get()->groupBy('passenger_id');
        $missing = [];
        foreach ($passengers as $p) {
            foreach (['id_doc', 'minor_auth'] as $type) {
                $i = ($items[$p->id] ?? collect())->firstWhere('doc_type', $type);
                if (! $i) {
                    continue;
                }
                $e = $this->effective($i, $p, $ctx, null, null);
                if ($e['applies'] && $e['status'] !== 'completo') {
                    $missing[] = ['passenger' => $p->full_name, 'passenger_id' => $p->id, 'type' => $type, 'label' => TravelDocItem::TYPES[$type],
                        'status' => $e['status'], 'status_label' => TravelDocItem::STATUS_LABELS[$e['status']]];
                }
            }
        }

        return ['applies' => true, 'blocked' => $missing !== [], 'missing' => $missing, 'services' => $ctx['mapi']];
    }

    /** Bloqueo para la emisión del voucher de una entrada MAPI (consultado por VoucherService::check). */
    public function voucherBlock(BookedService $s): ?array
    {
        if (! $this->isMapi($s) || ! ($r = Reservation::find($s->reservation_id))) {
            return null;
        }
        $gate = $this->gate($r);
        if (! $gate['blocked']) {
            return null;
        }
        $who = collect($gate['missing'])->map(fn ($m) => "{$m['passenger']} ({$m['label']})")->unique()->take(3)->implode(', ');

        return ['code' => 'DOC-BR-010', 'status' => 422, 'text' => "Entrada nominativa MAPI bloqueada: falta documentación validada de {$who}.", 'missing' => $gate['missing']];
    }

    // ── Acciones sobre ítems (DOC-BR-011: solo operations.travel_docs) ──────────────────────

    public function item(Reservation $r, string $passengerId, string $type): TravelDocItem
    {
        if (! isset(TravelDocItem::TYPES[$type]) || ! Str::isUuid($passengerId)) {
            throw ApiException::notFound();
        }
        $this->ensure(collect([$r]));

        return TravelDocItem::with('passenger')->where('reservation_id', $r->id)->where('passenger_id', $passengerId)->where('doc_type', $type)->first() ?? throw ApiException::notFound();
    }

    /** POST …/files (DOC-BR-013): PDF/JPG/PNG ≤ 10 MB con antivirus (simulado: «eicar» en el nombre = infectado) → recibido. */
    public function upload(TravelDocItem $i, array $data, User $actor, string $by = 'user'): TravelDocItem
    {
        if (in_array($i->doc_type, TravelDocItem::DATA_TYPES, true)) {
            throw ApiException::unprocessable('DOC-BR-013', 'Este ítem se completa con datos, no con un archivo.');
        }
        $name = mb_substr(basename(trim((string) ($data['file_name'] ?? ''))), 0, 200) ?: throw ApiException::invalid('file_name', 'Adjunte el archivo.');
        $mime = (string) ($data['mime_type'] ?? '');
        if (! isset(self::MIME[$mime])) {
            throw ApiException::unprocessable('DOC-BR-013', 'Solo se aceptan archivos PDF, JPG o PNG.');
        }
        $content = isset($data['content_base64']) ? base64_decode((string) $data['content_base64'], true) : null;
        if ($content === false) {
            throw ApiException::invalid('content_base64', 'El archivo no es válido.');
        }
        $size = $content !== null ? strlen($content) : (int) ($data['size'] ?? 0);
        if ($size <= 0) {
            throw ApiException::invalid('content_base64', 'El archivo está vacío.');
        }
        if ($size > self::MAX_BYTES) {
            throw ApiException::unprocessable('DOC-BR-013', 'El archivo supera 10 MB.');
        }
        if (Str::contains(mb_strtolower($name), 'eicar')) {
            $this->audit->isolated('Documento de viaje', $i->reservation?->code ?? $i->reservation_id, 'cargar_rechazado', null, "{$name}: antivirus detectó amenaza");
            throw ApiException::unprocessable('DOC-BR-013', 'El antivirus detectó una amenaza: el archivo no se guardó.');
        }
        $expiry = $this->optionalDate($data['expiry_date'] ?? null, 'expiry_date');
        $path = null;
        $fileId = (string) Str::uuid7();
        if ($content !== null) {
            $path = "tenants/{$this->tenant->id()}/travel-docs/{$fileId}.".self::MIME[$mime];
            Storage::disk((string) config('turismo.evidence_disk', 'local'))->put($path, $content);
        }
        $before = TravelDocItem::STATUS_LABELS[$i->status];
        $i->forceFill([
            'status' => 'recibido', 'file_id' => $fileId, 'file_name' => $name, 'file_mime' => $mime, 'file_size' => $size, 'storage_path' => $path, 'scan_status' => 'limpio',
            'uploaded_by_type' => in_array($by, ['user', 'client', 'agency'], true) ? $by : 'user', 'uploaded_at' => now(),
            'validated_by' => null, 'validated_at' => null, 'observation' => null,
            'doc_number' => trim((string) ($data['doc_number'] ?? '')) ?: $i->doc_number, 'expiry_date' => $expiry?->toDateString() ?? $i->expiry_date?->toDateString(),
        ])->save();
        $this->log($i, 'cargar', $before, "{$name}", $actor);

        return $i->fresh();
    }

    /** POST …/validate (§4.4): requiere archivo; id_doc requiere número y vencimiento conforme a DOC-BR-001/002; menor DOC-BR-006. */
    public function validate(TravelDocItem $i, array $data, User $actor): TravelDocItem
    {
        if (in_array($i->doc_type, TravelDocItem::DATA_TYPES, true)) {
            throw ApiException::unprocessable('DOC-STATE', 'Este ítem se completa registrando sus datos.');
        }
        if ($i->status === 'completo') {
            throw ApiException::rule('DOC-STATE', 'El documento ya está validado.');
        }
        $r = Reservation::findOrFail($i->reservation_id);
        $p = $i->passenger;
        $ctx = $this->context($r);
        [$applies, $reason] = $this->applies($i->doc_type, $p, $ctx, TravelDocSpecial::where('passenger_id', $p->id)->first());
        if (! $applies) {
            throw ApiException::unprocessable('DOC-STATE', "No aplica: {$reason}.");
        }
        if ($i->file_name === null) {
            throw $i->doc_type === 'minor_auth'
                ? ApiException::unprocessable('DOC-BR-006', 'Cargue el permiso notarial o la acreditación de filiación antes de validar.')
                : ApiException::unprocessable('DOC-BR-013', 'Cargue el archivo antes de validar.');
        }
        $number = trim((string) ($data['doc_number'] ?? '')) ?: $i->doc_number;
        $expiry = $this->optionalDate($data['expiry_date'] ?? null, 'expiry_date') ?? $i->expiry_date;
        if ($i->doc_type === 'id_doc') {
            if (! $number) {
                throw ApiException::invalid('doc_number', 'Indique el número del documento.');
            }
            if (! $expiry) {
                throw ApiException::invalid('expiry_date', 'Indique la fecha de vencimiento del documento.');
            }
            $min = $this->minExpiry($p, $ctx);
            if ($min && $expiry->lt($min)) {
                throw $this->isForeign($p)
                    ? ApiException::unprocessable('DOC-BR-001', "El pasaporte vence el {$expiry->format('d/m/Y')}: para extranjeros debe estar vigente hasta el {$min->format('d/m/Y')} (fin del viaje + {$this->settings()['passport_months']} meses).")
                    : ApiException::unprocessable('DOC-BR-002', "El documento vence el {$expiry->format('d/m/Y')}, antes del fin del viaje ({$min->format('d/m/Y')}).");
            }
        }
        $before = TravelDocItem::STATUS_LABELS[$i->status];
        $i->forceFill(['status' => 'completo', 'doc_number' => $number, 'expiry_date' => $expiry?->toDateString(), 'validated_by' => $actor->id, 'validated_at' => now(),
            'observation' => null, 'note' => isset($data['note']) ? (mb_substr(trim((string) $data['note']), 0, 300) ?: null) : $i->note])->save();
        $this->log($i, 'validar', $before, 'Completo'.($number ? " · {$number}" : '').($expiry ? ' · vence '.$expiry->format('d/m/Y') : ''), $actor);

        return $i->fresh();
    }

    /** POST …/observe: motivo obligatorio; notifica al cliente. */
    public function observe(TravelDocItem $i, string $reason, User $actor): TravelDocItem
    {
        if ($i->status === 'observado') {
            throw ApiException::rule('DOC-STATE', 'El documento ya está observado.');
        }
        if (in_array($i->doc_type, TravelDocItem::DATA_TYPES, true)) {
            throw ApiException::unprocessable('DOC-STATE', 'Este ítem se completa registrando sus datos.');
        }
        $reason = trim($reason) ?: throw ApiException::invalid('reason', 'Indique el motivo de la observación.');
        $before = TravelDocItem::STATUS_LABELS[$i->status];
        $i->forceFill(['status' => 'observado', 'observation' => mb_substr($reason, 0, 2000), 'validated_by' => null, 'validated_at' => null])->save();
        $this->log($i, 'observar', $before, "Observado: {$reason}", $actor);
        $r = Reservation::with('client')->find($i->reservation_id);
        if ($email = $r?->client?->email) {
            $this->notify->push('documentos.observado', 'email', $email, "Documento observado · {$r->code}",
                'Revisamos el documento «'.TravelDocItem::TYPES[$i->doc_type]."» de {$i->passenger?->full_name}: {$reason}. Por favor cárguelo nuevamente.",
                ['client_id' => $r->client_id, 'related_type' => 'booking_reservations', 'related_id' => $r->id]);
        }

        return $i->fresh();
    }

    /** PUT …/emergency-contact (DOC-BR-008). */
    public function contact(Reservation $r, Passenger $p, array $data, User $actor): TravelDocContact
    {
        $name = trim((string) ($data['name'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        if ($name === '' || $phone === '') {
            throw ApiException::unprocessable('DOC-BR-008', 'El contacto de emergencia requiere nombre y teléfono.');
        }
        if (! preg_match('/^[+\d][\d\s\-()]{5,29}$/', $phone)) {
            throw ApiException::invalid('phone', 'Indique un teléfono válido.');
        }
        $relation = in_array($data['relation'] ?? null, TravelDocContact::RELATIONS, true) ? $data['relation'] : throw ApiException::invalid('relation', 'Elija la relación con el pasajero.');
        $this->ensure(collect([$r]));
        $contact = TravelDocContact::updateOrCreate(['passenger_id' => $p->id], ['name' => mb_substr($name, 0, 150), 'phone' => $phone, 'relation' => $relation]);
        $item = TravelDocItem::where('passenger_id', $p->id)->where('doc_type', 'emergency')->first();
        $item?->forceFill(['status' => 'completo', 'validated_by' => $actor->id, 'validated_at' => now()])->save();
        $this->audit->log('Documento de viaje', $r->code, 'registrar_contacto', null, "{$p->full_name} · {$relation}", actor: $actor);

        return $contact;
    }

    /** PUT …/special-attention (DOC-BR-009): indicador + nota operativa, sin términos clínicos. */
    public function special(Reservation $r, Passenger $p, array $data, User $actor): TravelDocSpecial
    {
        if (! array_key_exists('requires_attention', $data)) {
            throw ApiException::invalid('requires_attention', 'Indique si requiere atención especial.');
        }
        $flag = filter_var($data['requires_attention'], FILTER_VALIDATE_BOOLEAN);
        $note = mb_substr(trim((string) ($data['operational_note'] ?? '')), 0, 300) ?: null;
        if ($flag && ! $note) {
            throw ApiException::invalid('operational_note', 'Describa la necesidad operativa (p. ej. silla de ruedas, asiento cerca del pasillo).');
        }
        $lower = mb_strtolower((string) $note);
        $hits = array_values(array_filter($this->settings()['clinical_terms'], fn ($t) => $t !== '' && preg_match('/\b'.preg_quote(mb_strtolower($t), '/').'\b/u', $lower)));
        if ($hits !== []) {
            throw ApiException::unprocessable('DOC-BR-009', 'La nota no puede incluir datos clínicos ('.implode(', ', $hits).'): registre solo la necesidad operativa.');
        }
        $this->ensure(collect([$r]));
        $special = TravelDocSpecial::updateOrCreate(['passenger_id' => $p->id], ['requires_attention' => $flag, 'operational_note' => $flag ? $note : null]);
        TravelDocItem::where('passenger_id', $p->id)->where('doc_type', 'special')->first()?->forceFill(['status' => $flag ? 'completo' : 'pendiente'])->save();
        $this->audit->log('Documento de viaje', $r->code, 'declarar_atencion', null, "{$p->full_name} · ".($flag ? "requiere atención: {$note}" : 'sin atención especial'), actor: $actor);

        return $special;
    }

    // ── Solicitudes y recordatorios ──────────────────────────────────────────────────────────

    /** POST /requests: solicitud al cliente (Notify por canal) y registro en el historial. */
    public function request(Reservation $r, array $data, ?User $actor, string $trigger = 'manual', ?int $daysBefore = null): TravelDocReminder
    {
        $summary = $this->summary($r);
        if ($summary['pending'] === 0) {
            throw ApiException::rule('DOC-STATE', 'La reserva tiene toda su documentación completa.');
        }
        $channels = array_values(array_intersect(self::CHANNELS, (array) ($data['channels'] ?? $this->settings()['channels']))) ?: $this->settings()['channels'];
        $r->loadMissing('client');
        $lead = Passenger::where('reservation_id', $r->id)->orderByDesc('is_lead')->orderBy('seq')->first();
        $email = $r->client?->email ?? $lead?->email;
        $phone = $r->client?->phone ?? $lead?->phone;
        $recipient = $email ?? $phone ?? ($r->client?->name ?? 'Cliente');
        $missing = collect($summary['passengers'])->flatMap(fn ($p) => collect($p['items'])->where('status', '<>', 'completo')->map(fn ($i) => "{$p['name']}: {$i['label']}"))->take(12);
        $message = trim((string) ($data['message'] ?? '')) ?: null;
        $event = $trigger === 'auto' ? 'documentos.recordatorio' : 'documentos.solicitud';
        $subject = ($trigger === 'auto' ? 'Recordatorio: documentos de viaje pendientes' : 'Documentos de viaje pendientes')." · {$r->code}";
        $body = ($message ? "{$message}\n\n" : '')."Para su viaje del {$r->travel_from?->format('d/m/Y')} faltan:\n- ".$missing->implode("\n- ")
            ."\n\nCárguelos desde su portal: /portal/reservas/{$r->code}";
        $meta = ['client_id' => $r->client_id, 'related_type' => 'booking_reservations', 'related_id' => $r->id];
        foreach ($channels as $channel) {
            $to = $channel === 'Email' ? $email : $phone;
            if ($to) {
                $this->notify->push($event, $channel === 'Email' ? 'email' : 'whatsapp', $to, $subject, $body, $meta);
            }
        }
        $reminder = TravelDocReminder::create(['reservation_id' => $r->id, 'sent_at' => now(), 'sent_on' => $this->today()->toDateString(), 'channels' => implode(', ', $channels),
            'recipient' => mb_substr($recipient, 0, 190), 'trigger' => $trigger, 'days_before' => $daysBefore, 'items_count' => min(32767, $summary['pending']),
            'sent_by' => $actor?->id, 'message' => $message ? mb_substr($message, 0, 500) : null]);
        $this->audit->log('Documento de viaje', $r->code, 'solicitar_documentos', null, ($trigger === 'auto' ? 'Recordatorio automático' : 'Solicitud')
            ." · {$summary['pending']} ítem(s) · ".implode(', ', $channels)." · {$recipient}", actor: $actor, system: $actor === null);

        return $reminder;
    }

    /** JOB-11 `TravelDocReminders`: reservas incompletas a N días (reminder_days) o menos que el mínimo; un envío automático por día. */
    public function remind(?User $actor = null): array
    {
        $days = $this->settings()['reminder_days'];
        $min = min($days);
        $today = $this->today();
        $sent = 0;
        $skipped = 0;
        foreach ($this->upcoming()->whereDate('travel_from', '>=', $today->toDateString())->orderBy('travel_from')->get() as $r) {
            $left = (int) $today->diffInDays($r->travel_from->copy()->startOfDay(), false);
            if (! in_array($left, $days, true) && $left > $min) {
                continue;
            }
            if (TravelDocReminder::where('reservation_id', $r->id)->where('trigger', 'auto')->whereDate('sent_on', $today->toDateString())->exists()) {
                $skipped++;

                continue;
            }
            try {
                $this->request($r, [], null, 'auto', $left);
                $sent++;
            } catch (ApiException) {
                // Documentación completa: no se recuerda.
            }
        }
        $result = "{$sent} recordatorio(s) enviados".($skipped ? " · {$skipped} ya enviados hoy" : '');
        $this->audit->log('Documentos de viaje', 'JOB-11', 'ejecutar_recordatorios', null, $result, actor: $actor, system: $actor === null);

        return ['sent' => $sent, 'skipped' => $skipped, 'summary' => $result];
    }

    /** Bitácora de la reserva: auditoría de documentos + historial de envíos. */
    public function log(TravelDocItem $i, string $action, ?string $before, string $after, User $actor): void
    {
        $r = $i->relationLoaded('reservation') ? $i->reservation : Reservation::find($i->reservation_id);
        $this->audit->log('Documento de viaje', $r?->code ?? $i->reservation_id, $action, $before,
            "{$i->passenger?->full_name} · ".TravelDocItem::TYPES[$i->doc_type]." · {$after}", actor: $actor);
    }

    /** Fecha del tenant a medianoche UTC: comparable con las columnas `date` (casteadas en UTC). */
    public function today(): Carbon
    {
        return Carbon::parse($this->clock->today()->toDateString());
    }

    private function optionalDate(mixed $value, string $field): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw ApiException::invalid($field, 'Indique una fecha válida.');
        }

        return Carbon::parse($value)->startOfDay();
    }
}
