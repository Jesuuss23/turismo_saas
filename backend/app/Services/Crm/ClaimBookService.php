<?php

namespace App\Services\Crm;

use App\Exceptions\ApiException;
use App\Models\Bookings\Reservation;
use App\Models\Crm\AfterSalesCase;
use App\Models\Crm\CaseEvent;
use App\Models\Crm\ClaimBookSetting;
use App\Models\Crm\Client;
use App\Models\Iam\Sede;
use App\Models\Iam\User;
use App\Models\Sys\Holiday;
use App\Services\AuditLogger;
use App\Services\Notify;
use App\Services\SequenceService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Libro de Reclamaciones virtual (spec 12 §4 Hoja, §5 LR-BR-01..10): alta con correlativo por sede y plazo en días hábiles,
 * evaluación, respuesta motivada enviada al consumidor, reenvío, cierre y vínculos. Las hojas son casos de postventa con `is_book`.
 */
class ClaimBookService
{
    public const STATUS_LABELS = ['received' => 'Recibida', 'in_review' => 'En evaluación', 'answered' => 'Respondida', 'closed' => 'Cerrada'];

    /** Estado CRM equivalente (Postventa y Cliente 360 las muestran sin cambios). */
    public const CRM_STATUS = ['received' => 'abierta', 'in_review' => 'en_proceso', 'answered' => 'resuelta', 'closed' => 'cerrada'];

    public const CHANNELS = ['web' => 'Sitio web', 'customer_portal' => 'Portal del cliente', 'passenger_portal' => 'Portal del pasajero', 'in_person' => 'Presencial'];

    public const DOC_TYPES = ['DNI', 'CE', 'Pasaporte', 'RUC'];

    public const DECISIONS = ['fundado' => 'Fundado', 'fundado_parcial' => 'Fundado en parte', 'infundado' => 'Infundado'];

    public function __construct(
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
        private readonly BusinessDayCalculator $days,
        private readonly CrmClock $clock,
        private readonly TenantContext $tenant,
    ) {}

    public function settings(): ClaimBookSetting
    {
        return ClaimBookSetting::firstOrCreate([]);
    }

    public function today(): Carbon
    {
        return Carbon::parse($this->clock->today()->toDateString());
    }

    /**
     * Alta de la hoja (LR-BR-01..05). `$channel`: web | customer_portal | passenger_portal | in_person.
     * `$client`/`$reservation` vienen del token en los portales; nunca del cuerpo de la solicitud.
     */
    public function register(array $data, string $channel, ?User $submitter = null, ?Client $client = null, ?Reservation $reservation = null): AfterSalesCase
    {
        [$sede, $type, $consumer, $good, $detail, $request] = $this->validated($data);
        $settings = $this->settings();
        $receivedAt = now();
        $due = $this->days->addBusinessDays($this->today(), $settings->response_days);
        $client ??= $this->matchClient($consumer);
        $owner = $this->owner($settings);

        $case = DB::transaction(function () use ($sede, $type, $consumer, $good, $detail, $request, $channel, $submitter, $client, $reservation, $receivedAt, $due, $settings, $owner) {
            $number = $this->nextNumber($sede);
            $case = AfterSalesCase::create([
                'code' => $this->sequences->next('case'), 'client_id' => $client?->id, 'reservation_id' => $reservation?->id, 'origin' => 'libro',
                'type_code' => $type, 'priority' => $type === 'reclamo' ? 'alta' : 'media',
                'subject' => Str::limit(($type === 'reclamo' ? 'Reclamo' : 'Queja')." {$number}: {$good['description']}", 195, '…'),
                'description' => $detail, 'status' => 'abierta', 'owner_id' => $owner->id,
                'is_book' => true, 'book_number' => $number, 'sede_id' => $sede->id, 'channel' => $channel, 'book_status' => 'received',
                'consumer' => $consumer, 'consumer_email' => mb_strtolower($consumer['email']), 'good' => $good, 'detail' => $detail, 'request' => $request,
                'received_at' => $receivedAt, 'due_date' => $due->toDateString(), 'response_days' => $settings->response_days, 'submitted_by' => $submitter?->id,
            ]);
            $this->event($case, 'registro', null, 'abierta', "Hoja {$number} recibida por ".self::CHANNELS[$channel]."; plazo hasta el {$due->format('d/m/Y')} ({$settings->response_days} días hábiles)", $submitter);
            $this->audit->log('Libro de reclamaciones', $number, 'registrar', null,
                ($type === 'reclamo' ? 'Reclamo' : 'Queja').' · '.self::CHANNELS[$channel]." · {$sede->name} · vence {$due->format('d/m/Y')}", actor: $submitter);

            return $case;
        });

        // LR-BR-05: constancia al correo del consumidor y aviso al responsable.
        $this->notify->push('reclamacion.constancia', 'email', $consumer['email'], "Constancia de su hoja de reclamación {$case->book_number}",
            'Registramos su '.mb_strtolower($type === 'reclamo' ? 'Reclamo' : 'Queja')." {$case->book_number} el {$receivedAt->format('d/m/Y H:i')}. Le responderemos a este correo a más tardar el {$due->format('d/m/Y')}.",
            ['client_id' => $case->client_id, 'related_type' => 'case', 'related_id' => $case->id]);
        $case->forceFill(['ack_sent_at' => now()])->saveQuietly();
        $this->notify->push('reclamacion.nueva', 'portal', $owner, "Nueva hoja {$case->book_number}: {$case->subject}", "Vence el {$due->format('d/m/Y')}.",
            ['related_type' => 'case', 'related_id' => $case->id, 'link' => "/crm/reclamaciones/{$case->code}"]);

        return $case;
    }

    /** → in_review con responsable (y aviso). */
    public function review(AfterSalesCase $case, ?string $ownerId, User $actor): AfterSalesCase
    {
        $this->assertBook($case);
        if ($case->book_status !== 'received') {
            throw ApiException::conflict('LR-BR-07', 'Solo se evalúa una hoja recibida.');
        }
        $owner = $ownerId ? User::where('id', $ownerId)->where('status', User::ACTIVE)->first() : $actor;
        if (! $owner || ! $owner->hasPermissionTo('crm.claims.manage')) {
            throw ApiException::invalid('owner_id', 'El responsable debe gestionar el libro de reclamaciones.');
        }
        DB::transaction(function () use ($case, $owner, $actor) {
            $case->forceFill(['book_status' => 'in_review', 'status' => self::CRM_STATUS['in_review'], 'owner_id' => $owner->id])->save();
            $this->event($case, 'estado', 'abierta', 'en_proceso', "En evaluación · responsable {$owner->name}", $actor);
        });
        $this->audit->log('Libro de reclamaciones', $case->book_number, 'evaluar', 'Recibida', "En evaluación · {$owner->name}", actor: $actor);
        if ($owner->id !== $actor->id) {
            $this->notify->push('reclamacion.asignada', 'portal', $owner, "Hoja {$case->book_number} asignada", $case->subject,
                ['related_type' => 'case', 'related_id' => $case->id, 'link' => "/crm/reclamaciones/{$case->code}"]);
        }

        return $case->fresh();
    }

    /** → answered: respuesta motivada (≥ 40) con acciones; se envía al correo consignado (LR-BR-06). */
    public function respond(AfterSalesCase $case, array $data, User $actor): AfterSalesCase
    {
        $this->assertBook($case);
        if (! in_array($case->book_status, ['received', 'in_review'], true)) {
            throw ApiException::conflict('LR-BR-07', 'La hoja ya fue respondida.');
        }
        $decision = (string) ($data['decision'] ?? '');
        if (! isset(self::DECISIONS[$decision])) {
            throw ApiException::invalid('decision', 'Indique si el reclamo es fundado, fundado en parte o infundado.');
        }
        $text = trim((string) ($data['text'] ?? ''));
        if (mb_strlen($text) < 40) {
            throw ApiException::unprocessable('LR-BR-06', 'La respuesta debe estar motivada (mínimo 40 caracteres).');
        }
        $actions = trim((string) ($data['actions'] ?? ''));
        if ($actions === '') {
            throw ApiException::unprocessable('LR-BR-06', 'Indique las acciones adoptadas.');
        }
        $sentAt = now();
        $late = $this->today()->gt($case->due_date);
        $to = $case->consumer['email'];
        DB::transaction(function () use ($case, $decision, $text, $actions, $actor, $sentAt, $late, $to) {
            $from = $case->status;
            $case->forceFill([
                'book_status' => 'answered', 'status' => self::CRM_STATUS['answered'], 'resolution' => mb_substr(self::DECISIONS[$decision].": {$text}", 0, 5000), 'resolved_at' => $sentAt,
                'response' => ['decision' => $decision, 'text' => $text, 'actions' => $actions, 'answered_at' => $sentAt->toIso8601String(), 'answered_by' => $actor->name,
                    'answered_by_id' => $actor->id, 'sent_at' => $sentAt->toIso8601String(), 'sent_to' => $to, 'late' => $late,
                    'notification_id' => 'NTF-'.Str::upper(Str::random(8)), 'resends' => []],
            ])->save();
            $this->event($case, 'respuesta', $from, 'resuelta', self::DECISIONS[$decision].' · enviada a '.$to.($late ? ' · FUERA DE PLAZO' : ''), $actor);
        });
        $this->audit->log('Libro de reclamaciones', $case->book_number, 'responder', self::STATUS_LABELS['in_review'],
            self::DECISIONS[$decision].($late ? ' · FUERA DE PLAZO (vencía '.$case->due_date->format('d/m/Y').')' : ' · en plazo'), actor: $actor);
        $this->sendResponse($case->fresh(), $actor, 'enviar_respuesta');

        return $case->fresh();
    }

    /** Reenvío de la respuesta (queda registrado). */
    public function resend(AfterSalesCase $case, User $actor): AfterSalesCase
    {
        $this->assertBook($case);
        if (! in_array($case->book_status, ['answered', 'closed'], true)) {
            throw ApiException::conflict('LR-BR-07', 'La hoja aún no tiene respuesta.');
        }
        $response = $case->response;
        $response['resends'][] = ['at' => now()->toIso8601String(), 'by' => $actor->name, 'to' => $case->consumer['email']];
        $case->forceFill(['response' => $response])->save();
        $this->event($case, 'seguimiento', null, null, 'Respuesta reenviada a '.$case->consumer['email'], $actor);
        $this->sendResponse($case, $actor, 'reenviar_respuesta');

        return $case->fresh();
    }

    /** → closed: solo una hoja respondida (LR-BR-07); sincroniza el caso para Postventa. */
    public function close(AfterSalesCase $case, ?string $note, User $actor): AfterSalesCase
    {
        $this->assertBook($case);
        if ($case->book_status !== 'answered') {
            throw ApiException::conflict('LR-BR-07', 'Solo se cierra una hoja respondida.');
        }
        $note = trim((string) $note);
        if ($note === '') {
            throw ApiException::invalid('note', 'Indique el motivo del cierre.');
        }
        DB::transaction(function () use ($case, $note, $actor) {
            $case->forceFill(['book_status' => 'closed', 'status' => self::CRM_STATUS['closed'], 'closed_at' => now(), 'close_note' => mb_substr($note, 0, 2000)])->save();
            $this->event($case, 'estado', 'resuelta', 'cerrada', "Hoja cerrada: {$note}", $actor);
        });
        $this->audit->log('Libro de reclamaciones', $case->book_number, 'cerrar', 'Respondida', "Cerrada · {$note}", actor: $actor);

        return $case->fresh();
    }

    /** PATCH …/links: vincula cliente y/o reserva. */
    public function link(AfterSalesCase $case, array $data, User $actor): AfterSalesCase
    {
        $this->assertBook($case);
        $changes = [];
        if (array_key_exists('client_id', $data)) {
            $client = $data['client_id'] ? (Str::isUuid((string) $data['client_id']) ? Client::find($data['client_id']) : null) ?? throw ApiException::invalid('client_id', 'El cliente no existe.') : null;
            $changes['client_id'] = $client?->id;
        }
        if (array_key_exists('reservation_code', $data)) {
            $res = $data['reservation_code'] ? Reservation::where('code', $data['reservation_code'])->first() ?? throw ApiException::invalid('reservation_code', 'La reserva no existe.') : null;
            $changes['reservation_id'] = $res?->id;
            if ($res && ! array_key_exists('client_id', $changes)) {
                $changes['client_id'] = $res->client_id;
            }
        }
        if ($changes === []) {
            throw ApiException::invalid('client_id', 'Indique el cliente o la reserva a vincular.');
        }
        $case->forceFill($changes)->save();
        $case->load('client', 'reservation');
        $text = trim(($case->client ? "cliente {$case->client->name}" : 'sin cliente').($case->reservation ? " · reserva {$case->reservation->code}" : ''));
        $this->event($case, 'vinculo', null, null, "Vinculada: {$text}", $actor);
        $this->audit->log('Libro de reclamaciones', $case->book_number, 'vincular', null, $text, actor: $actor);

        return $case->fresh();
    }

    /** Estado del plazo (derivado): en_plazo, por_vencer, vencido, respondido_en_plazo, respondido_fuera_de_plazo. */
    public function deadline(AfterSalesCase $case): array
    {
        $settings = $this->settings();
        $today = $this->today();
        if (in_array($case->book_status, ['answered', 'closed'], true)) {
            $late = (bool) ($case->response['late'] ?? false);

            return ['state' => $late ? 'respondido_fuera_de_plazo' : 'respondido_en_plazo', 'label' => $late ? 'Respondido fuera de plazo' : 'Respondido en plazo',
                'business_days_left' => null, 'tone' => $late ? 'rose' : 'green'];
        }
        if ($today->gt($case->due_date)) {
            return ['state' => 'vencido', 'label' => 'Vencido', 'business_days_left' => -$this->days->between($case->due_date, $today), 'tone' => 'rose'];
        }
        $left = $this->days->between($today, $case->due_date);

        return $left <= $settings->alert_days
            ? ['state' => 'por_vencer', 'label' => "Por vencer ({$left} d hábiles)", 'business_days_left' => $left, 'tone' => 'amber']
            : ['state' => 'en_plazo', 'label' => "En plazo ({$left} d hábiles)", 'business_days_left' => $left, 'tone' => 'sky'];
    }

    /** JOB-15 `claims:deadline-alerts`: por vencer y vencidas → responsable con copia a Gerencia. */
    public function deadlineAlerts(?User $actor = null): string
    {
        $open = AfterSalesCase::where('is_book', true)->whereIn('book_status', ['received', 'in_review'])->with('owner')->get();
        $sent = 0;
        $managers = User::role('manager')->where('status', User::ACTIVE)->get();
        foreach ($open as $case) {
            $d = $this->deadline($case);
            if (! in_array($d['state'], ['por_vencer', 'vencido'], true)) {
                continue;
            }
            $event = $d['state'] === 'vencido' ? 'reclamacion.vencida' : 'reclamacion.por_vencer';
            $subject = "Hoja {$case->book_number} ".($d['state'] === 'vencido' ? 'vencida' : 'por vencer')." (vence {$case->due_date->format('d/m/Y')})";
            foreach (collect([$case->owner])->concat($managers)->filter()->unique('id') as $u) {
                $this->notify->push($event, 'portal', $u, $subject, $case->subject, ['related_type' => 'case', 'related_id' => $case->id, 'link' => "/crm/reclamaciones/{$case->code}"]);
            }
            $sent++;
        }
        $summary = $sent ? "{$sent} hoja(s) por vencer o vencidas: alerta enviada" : 'Sin hojas por vencer';
        $this->audit->log('Job', 'claims:deadline-alerts', 'ejecutar', null, $summary, actor: $actor, system: $actor === null);

        return $summary;
    }

    public function saveSettings(array $data, User $actor): ClaimBookSetting
    {
        $s = $this->settings();
        $before = "{$s->response_days} d hábiles · alerta {$s->alert_days} d · conservación {$s->retention_years} años";
        $int = function (string $key, int $min, int $max) use ($data, $s) {
            $v = $data[$key] ?? $s->{$key};
            if (! is_numeric($v) || (int) $v != $v || (int) $v < $min || (int) $v > $max) {
                throw ApiException::invalid($key, "Valor fuera de rango ({$min}–{$max}).");
            }

            return (int) $v;
        };
        $attrs = ['response_days' => $int('response_days', 1, 30), 'alert_days' => $int('alert_days', 0, 10), 'retention_years' => $int('retention_years', 2, 20)];
        if (array_key_exists('default_owner_id', $data)) {
            $owner = Str::isUuid((string) ($data['default_owner_id'] ?? '')) ? User::find($data['default_owner_id']) : null;
            if ($data['default_owner_id'] && (! $owner || ! $owner->hasPermissionTo('crm.claims.manage'))) {
                throw ApiException::invalid('default_owner_id', 'El responsable debe gestionar el libro de reclamaciones.');
            }
            $attrs['default_owner_id'] = $owner?->id;
        }
        DB::transaction(function () use ($s, $attrs, $data) {
            $s->fill($attrs)->save();
            foreach ((array) ($data['add_holidays'] ?? []) as $h) {
                $date = (string) ($h['date'] ?? '');
                $name = trim((string) ($h['name'] ?? ''));
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $name === '') {
                    throw ApiException::invalid('add_holidays', 'Indique la fecha y el nombre del feriado local.');
                }
                if (! Holiday::whereDate('date', $date)->exists()) {
                    Holiday::create(['tenant_id' => $this->tenant->id(), 'date' => $date, 'name' => mb_substr($name, 0, 120)]);
                }
            }
            foreach (array_filter((array) ($data['remove_holidays'] ?? []), fn ($id) => Str::isUuid((string) $id)) as $id) {
                Holiday::whereKey($id)->where('tenant_id', $this->tenant->id())->delete();
            }
        });
        $after = "{$s->response_days} d hábiles · alerta {$s->alert_days} d · conservación {$s->retention_years} años";
        // LR-BR-10: el plazo nuevo solo rige para las hojas nuevas (las existentes guardan su due_date y response_days).
        $this->audit->log('Configuración/claim_book', 'claim_book', 'actualizar', $before, $after, actor: $actor);

        return $s->fresh();
    }

    /** Datos del proveedor que encabezan la hoja (razón social, RUC, domicilio fiscal). */
    public function provider(): array
    {
        $t = $this->tenant->tenant();

        return ['name' => $t?->legal_name, 'trade_name' => $t?->trade_name, 'ruc' => $t?->ruc, 'address' => $t?->fiscal_address];
    }

    public function sedes()
    {
        return Sede::orderByDesc('is_main')->orderBy('name')->get(['id', 'code', 'name', 'city']);
    }

    /** Prefijo de la sede para el correlativo: LIM, CUS, ARE… (ciudad) o el código de la sede. */
    public function sedePrefix(Sede $sede): string
    {
        $city = Str::upper(Str::substr(Str::ascii((string) $sede->city), 0, 3));

        return preg_match('/^[A-Z]{3}$/', $city) ? $city : Str::upper((string) $sede->code);
    }

    private function sendResponse(AfterSalesCase $case, User $actor, string $action): void
    {
        $r = $case->response;
        $this->notify->push('reclamacion.respuesta', 'email', $case->consumer['email'], "Respuesta a su hoja de reclamación {$case->book_number}",
            self::DECISIONS[$r['decision']].".\n\n{$r['text']}\n\nAcciones adoptadas: {$r['actions']}",
            ['client_id' => $case->client_id, 'related_type' => 'case', 'related_id' => $case->id]);
        $this->audit->log('Libro de reclamaciones', $case->book_number, $action, null, 'Enviada a '.$case->consumer['email'], actor: $actor);
    }

    /** LR-BR-01: siguiente número de la sede en el año, sin saltos (la fila queda bloqueada hasta el commit). */
    private function nextNumber(Sede $sede): string
    {
        $year = (int) $this->today()->format('Y');
        $n = DB::selectOne('INSERT INTO crm_claim_book_counters (id, tenant_id, sede_id, year, last_number) VALUES (gen_random_uuid(), ?, ?, ?, 1)
            ON CONFLICT (tenant_id, sede_id, year) DO UPDATE SET last_number = crm_claim_book_counters.last_number + 1 RETURNING last_number',
            [$this->tenant->id(), $sede->id, $year])->last_number;

        return sprintf('LR-%s-%d-%06d', $this->sedePrefix($sede), $year, $n);
    }

    /** LR-BR-03/04 (Zod replica en Next.js). */
    private function validated(array $data): array
    {
        $sede = ! empty($data['sede_id']) ? Sede::where(Str::isUuid((string) $data['sede_id']) ? 'id' : 'code', $data['sede_id'])->first() : null;
        if (! $sede) {
            throw ApiException::invalid('sede_id', 'Seleccione la sede.');
        }
        $typeRaw = Str::lower(Str::ascii(trim((string) ($data['type'] ?? ''))));
        $type = match ($typeRaw) {
            'reclamo' => 'reclamo', 'queja' => 'queja', default => throw ApiException::invalid('type', 'Elija Reclamo o Queja.')
        };
        $c = (array) ($data['consumer'] ?? []);
        $name = trim((string) ($c['name'] ?? ''));
        $docType = (string) ($c['doc_type'] ?? 'DNI');
        $docNumber = trim((string) ($c['doc_number'] ?? ''));
        $email = trim((string) ($c['email'] ?? ''));
        $address = trim((string) ($c['address'] ?? ''));
        if (mb_strlen($name) < 3) {
            throw ApiException::invalid('consumer.name', 'Indique el nombre completo.');
        }
        if (! in_array($docType, self::DOC_TYPES, true)) {
            throw ApiException::invalid('consumer.doc_type', 'Tipo de documento inválido.');
        }
        if ($docType === 'DNI' ? ! preg_match('/^\d{8}$/', $docNumber) : mb_strlen($docNumber) < 5) {
            throw ApiException::invalid('consumer.doc_number', $docType === 'DNI' ? 'El DNI tiene 8 dígitos.' : 'Indique el número de documento.');
        }
        if ($address === '') {
            throw ApiException::invalid('consumer.address', 'Indique el domicilio.');
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ApiException::invalid('consumer.email', 'Indique un correo válido.');
        }
        $minor = filter_var($c['is_minor'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $guardian = null;
        if ($minor) {
            $g = (array) ($c['guardian'] ?? []);
            $gName = trim((string) ($g['name'] ?? ''));
            $gDoc = trim((string) ($g['doc_number'] ?? ''));
            if (mb_strlen($gName) < 3 || mb_strlen($gDoc) < 5) {
                throw ApiException::unprocessable('LR-BR-04', 'El consumidor es menor de edad: indique el nombre y el documento del padre, madre o apoderado.');
            }
            $guardian = ['name' => $gName, 'doc_type' => (string) ($g['doc_type'] ?? 'DNI'), 'doc_number' => $gDoc, 'relation' => trim((string) ($g['relation'] ?? 'Apoderado'))];
        }
        $g = (array) ($data['good'] ?? []);
        $kind = in_array($g['kind'] ?? 'Servicio', ['Servicio', 'Producto'], true) ? ($g['kind'] ?? 'Servicio') : 'Servicio';
        $goodDescription = trim((string) ($g['description'] ?? ''));
        if (mb_strlen($goodDescription) < 3) {
            throw ApiException::invalid('good.description', 'Describa el bien contratado.');
        }
        $amount = isset($g['amount']) && $g['amount'] !== '' ? (float) $g['amount'] : null;
        $detail = trim((string) ($data['detail'] ?? ''));
        if (mb_strlen($detail) < 20) {
            throw ApiException::invalid('detail', 'Describa el detalle (mínimo 20 caracteres).');
        }
        $request = trim((string) ($data['request'] ?? ''));
        if (mb_strlen($request) < 5) {
            throw ApiException::invalid('request', 'Indique su pedido.');
        }
        if (! filter_var($data['accept_email'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw ApiException::invalid('accept_email', 'Acepte recibir la respuesta por correo electrónico.');
        }

        return [$sede, $type,
            ['name' => mb_substr($name, 0, 160), 'doc_type' => $docType, 'doc_number' => mb_substr($docNumber, 0, 20), 'address' => mb_substr($address, 0, 255),
                'email' => mb_substr($email, 0, 160), 'phone' => mb_substr(trim((string) ($c['phone'] ?? '')), 0, 30) ?: null, 'is_minor' => $minor, 'guardian' => $guardian],
            ['kind' => $kind, 'description' => mb_substr($goodDescription, 0, 255), 'amount' => $amount, 'currency' => in_array($g['currency'] ?? 'PEN', ['PEN', 'USD'], true) ? ($g['currency'] ?? 'PEN') : 'PEN'],
            mb_substr($detail, 0, 5000), mb_substr($request, 0, 2000)];
    }

    /** Cliente existente por correo o documento (vinculación automática; si no, queda para «Vincular»). */
    private function matchClient(array $consumer): ?Client
    {
        return Client::whereRaw('lower(email) = ?', [mb_strtolower($consumer['email'])])->first()
            ?? Client::where('doc_number', $consumer['doc_number'])->first();
    }

    private function owner(ClaimBookSetting $settings): User
    {
        $owner = $settings->default_owner_id ? User::where('id', $settings->default_owner_id)->where('status', User::ACTIVE)->first() : null;

        return $owner
            ?? User::role('manager')->where('status', User::ACTIVE)->orderBy('name')->get()->first(fn ($u) => $u->hasPermissionTo('crm.claims.manage'))
            ?? User::permission('crm.claims.manage')->where('status', User::ACTIVE)->orderBy('name')->first()
            ?? throw ApiException::conflict('LR-OWNER', 'No hay un responsable del libro de reclamaciones configurado.');
    }

    private function assertBook(AfterSalesCase $case): void
    {
        if (! $case->is_book) {
            throw ApiException::notFound();
        }
    }

    private function event(AfterSalesCase $case, string $event, ?string $from, ?string $to, string $message, ?User $actor): void
    {
        CaseEvent::create(['case_id' => $case->id, 'event' => $event, 'from_status' => $from, 'to_status' => $to, 'message' => $message,
            'user_id' => $actor?->id, 'created_at' => now()]);
    }
}
