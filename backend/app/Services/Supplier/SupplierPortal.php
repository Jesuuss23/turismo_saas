<?php

namespace App\Services\Supplier;

use App\Exceptions\ApiException;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\Passenger;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Provider;
use App\Models\Catalog\ServiceRate;
use App\Models\Catalog\SupplierService;
use App\Models\Finance\Payable;
use App\Models\Iam\User;
use App\Models\Ops\Incident;
use App\Models\Ops\IncidentLog;
use App\Models\Ops\RoomingList;
use App\Models\Ops\RoomingRoom;
use App\Models\Ops\Voucher;
use App\Models\Supplier\RateRequest;
use App\Models\Supplier\RequestResponse;
use App\Models\Supplier\SupplierInvoice;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Crm\CrmClock;
use App\Services\Finance\PayableService;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Read models de la extranet (spec 06 §3.5): cada respuesta se arma con una whitelist de campos. Nunca se exponen precio
 * de venta, margen, cliente (id, email, teléfono), documentos de pasajeros ni datos de otros proveedores (SUP-BR-02).
 */
class SupplierPortal
{
    /** Estados de la extranet sobre booking_services.status (§4.1). */
    public const STATES = ['pending' => 'Por responder', 'alternative' => 'Alternativa propuesta', 'confirmado' => 'Confirmado', 'rechazado' => 'Rechazado',
        'completado' => 'Completado', 'cancelado' => 'Cancelado', 'en_curso' => 'En curso'];

    public function __construct(
        private readonly CrmClock $clock,
        private readonly TenantContext $tenant,
        private readonly SupplierAccess $access,
    ) {}

    public function me(User $user, Provider $provider): array
    {
        return [
            'provider' => ['id' => $provider->id, 'ref' => $provider->ref, 'name' => $provider->name, 'type' => $provider->type, 'currency' => $provider->currency,
                'payment_terms' => $provider->payment_terms, 'status' => $provider->effectiveStatus()],
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'provider_role' => $user->provider_role ?? 'admin',
                'provider_role_label' => SupplierAccess::ROLES[$user->provider_role ?? 'admin']],
            'permissions' => $this->access->permissions($user),
            'agency' => $this->tenant->tenant()?->trade_name ?? $this->tenant->tenant()?->legal_name,
            'today' => $this->today(),
        ];
    }

    /** 4 KPIs del marco común (§2). */
    public function dashboard(Provider $provider): array
    {
        $open = $this->services($provider)->whereIn('status', SupplierRequests::OPEN)->get(['id']);
        $alternatives = RequestResponse::whereIn('reservation_service_id', $open->pluck('id'))->where('type', RequestResponse::ALTERNATIVE)
            ->where('review_status', 'pendiente')->distinct()->count('reservation_service_id');
        $today = $this->clock->today();
        $arrivals = $this->services($provider)->whereIn('status', [BookedService::PENDING, BookedService::REQUESTED, BookedService::CONFIRMED, BookedService::IN_PROGRESS])
            ->whereBetween('service_date', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()])->get(['pax']);
        $balances = $this->balances(Payable::where('provider_id', $provider->id)->whereNotIn('status', ['pagada', 'anulada'])->get());

        return [
            'pending_requests' => $open->count() - $alternatives, 'alternatives_in_review' => $alternatives, 'response_target_hours' => 24,
            'arrivals_7d' => ['services' => $arrivals->count(), 'pax' => (int) $arrivals->sum('pax')],
            'balance' => $balances, 'open_incidents' => $this->incidents($provider)->whereIn('status', Incident::ACTIVE)->count(),
        ];
    }

    // ---------------------------------------------------------------- Solicitudes

    /** SUP-BR-03: servicios del proveedor en reservas que no están en borrador. */
    public function services(Provider $provider): Builder
    {
        return BookedService::where('provider_id', $provider->id)->whereHas('reservation', fn ($q) => $q->where('status', '<>', Reservation::DRAFT));
    }

    /** @return list<array> pendientes primero, luego por fecha. */
    public function requests(Provider $provider, ?string $state, ?string $q): array
    {
        $rows = $this->services($provider)->with('reservation')->orderBy('service_date')->orderBy('service_time')->limit(500)->get()
            ->map(fn (BookedService $s) => $this->requestRow($s));
        if ($state) {
            $rows = $rows->where('state', $state);
        }
        if ($q = mb_strtolower(trim((string) $q))) {
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower("{$r['reservation_code']} {$r['service_label']} {$r['holder_label']} {$r['code']}"), $q));
        }

        return $rows->sortBy(fn ($r) => [in_array($r['state'], ['pending', 'alternative'], true) ? 0 : 1, $r['service_date'], $r['service_time'] ?? ''])->values()->all();
    }

    public function requestRow(BookedService $s): array
    {
        $response = RequestResponse::where('reservation_service_id', $s->id)->latest('responded_at')->first();
        $state = in_array($s->status, SupplierRequests::OPEN, true)
            ? ($response?->type === RequestResponse::ALTERNATIVE && $response->review_status === 'pendiente' ? 'alternative' : 'pending')
            : $s->status;
        $reservation = $s->reservation;
        $days = $this->daysTo($s->service_date);

        return [
            'id' => $s->id, 'code' => $s->code, 'reservation_code' => $reservation?->code, 'service_label' => $s->name,
            'service_date' => $s->service_date->toDateString(), 'service_time' => $s->service_time ? substr((string) $s->service_time, 0, 5) : null,
            'days_to' => (int) $days, 'holder_label' => self::holderLabel($reservation, $s->pax), 'pax' => $s->pax,
            'unit_cost' => (string) $s->cost_amount, 'currency' => $reservation?->currency,
            'status' => $s->status, 'state' => $state, 'state_label' => self::STATES[$state] ?? $state, 'confirmation_code' => $s->confirmation_code,
            'requested_at' => $s->requested_at?->toIso8601String(), 'can_respond' => in_array($state, ['pending', 'alternative'], true),
            'can_propose' => $state === 'pending',
            'response' => $response ? $this->responseRow($response) : null,
        ];
    }

    public function requestDetail(BookedService $s): array
    {
        $row = $this->requestRow($s);
        $list = RoomingList::where('reservation_id', $s->reservation_id)->where('hotel_provider_id', $s->provider_id)->whereNotNull('sent_at')->first();
        $voucher = Voucher::where('reservation_service_id', $s->id)->where('provider_id', $s->provider_id)->latest('issued_at')->first();

        return $row + [
            'responses' => RequestResponse::where('reservation_service_id', $s->id)->latest('responded_at')->get()->map(fn ($r) => $this->responseRow($r))->all(),
            'rooming' => $list ? $this->roomingRow($list) : null,
            'voucher' => $voucher ? $this->voucherRow($voucher) : null,
            'alternative_services' => SupplierService::where('provider_id', $s->provider_id)->where('status', 'activo')->orderBy('name')->get()
                ->filter(fn (SupplierService $x) => $x->effectiveStatus() === 'activo')->map(fn ($x) => ['id' => $x->id, 'name' => $x->name, 'unit' => $x->unit])->values()->all(),
        ];
    }

    public function responseRow(RequestResponse $r): array
    {
        return ['id' => $r->id, 'type' => $r->type, 'responded_at' => $r->responded_at->toIso8601String(), 'confirmed_by_name' => $r->confirmed_by_name,
            'confirmation_code' => $r->confirmation_code, 'rejection_reason' => $r->rejection_reason, 'alt_kind' => $r->alt_kind,
            'alt_date' => $r->alt_date?->toDateString(), 'alt_service' => $r->altService?->name, 'alt_net_cost' => $r->alt_net_cost !== null ? (string) $r->alt_net_cost : null,
            'currency' => $r->currency, 'hold_until' => $r->hold_until?->toDateString(), 'note' => $r->note, 'review_status' => $r->review_status,
            'review_label' => $r->review_status ? RequestResponse::REVIEW_LABELS[$r->review_status] : null, 'review_note' => $r->review_note];
    }

    /** "Grupo: {título antes de ' — '}" con 6+ pax; si no, "Titular: {primer pasajero}" (§3.5). */
    public static function holderLabel(?Reservation $reservation, ?int $pax): string
    {
        if (! $reservation) {
            return '—';
        }
        if (($pax ?? 0) >= 6) {
            return 'Grupo: '.trim(Str::before($reservation->title, ' — '));
        }
        $first = Passenger::where('reservation_id', $reservation->id)->orderBy('seq')->value('full_name');

        return 'Titular: '.($first ?: '—');
    }

    // ---------------------------------------------------------------- Rooming

    /** @return list<array> */
    public function roomingLists(Provider $provider): array
    {
        return RoomingList::where('hotel_provider_id', $provider->id)->whereNotNull('sent_at')->with('reservation')->orderBy('check_in')->get()
            ->map(fn (RoomingList $l) => $this->roomingRow($l))->all();
    }

    public function roomingRow(RoomingList $l): array
    {
        $today = $this->clock->today()->toDateString();
        $rooms = RoomingRoom::where('rooming_list_id', $l->id)->with('guests.passenger')->orderBy('room_number')->get();
        $state = $l->hotel_confirmed_at ? 'confirmada' : ($l->hotel_ack_at ? 'recibida' : 'por_acusar');
        $pax = $rooms->sum(fn ($r) => $r->guests->count());

        return [
            'id' => $l->id, 'reservation_code' => $l->reservation?->code, 'holder_label' => self::holderLabel($l->reservation, $pax ?: null),
            'check_in' => $l->check_in->toDateString(), 'check_out' => $l->check_out->toDateString(), 'nights' => (int) $l->check_in->diffInDays($l->check_out),
            'arrival' => $l->check_in->toDateString() > $today ? 'Llega en '.$this->daysTo($l->check_in).' d'
                : ($l->check_out->toDateString() > $today ? 'En casa' : 'Salió'),
            'version' => $l->sent_version, 'sent_at' => $l->sent_at?->toIso8601String(), 'modified' => $l->status === RoomingList::MODIFIED,
            'state' => $state, 'state_label' => ['por_acusar' => 'Por acusar recibo', 'recibida' => 'Recibida', 'confirmada' => 'Confirmada por el hotel'][$state],
            'ack_at' => $l->hotel_ack_at?->toIso8601String(), 'confirmed_at' => $l->hotel_confirmed_at?->toIso8601String(), 'lock_version' => $l->lock_version,
            'rooms_count' => $rooms->count(), 'guests_count' => $pax,
            'rooms' => $rooms->map(fn (RoomingRoom $r) => ['id' => $r->id, 'room_number' => $r->room_number, 'room_type' => $r->room_type, 'status' => $r->status,
                'hotel_note' => $r->hotel_note, 'guests' => $r->guests->map(fn ($g) => $g->passenger?->full_name)->filter()->values()->all()])->all(),
        ];
    }

    // ---------------------------------------------------------------- Vouchers

    public function vouchers(Provider $provider, ?string $status, ?string $q): array
    {
        return Voucher::where('provider_id', $provider->id)->with('reservation:id,code')
            ->when($status, fn ($x) => $x->where('status', $status))
            ->when(trim((string) $q), fn ($x, $t) => $x->where(fn ($w) => $w->whereRaw('lower(code) like ?', ['%'.mb_strtolower($t).'%'])
                ->orWhereRaw('lower(service_label) like ?', ['%'.mb_strtolower($t).'%'])))
            ->orderByDesc('service_date')->limit(300)->get()->map(fn (Voucher $v) => $this->voucherRow($v))->all();
    }

    public function voucherRow(Voucher $v): array
    {
        return ['id' => $v->id, 'code' => $v->code, 'version' => $v->version, 'issued_at' => $v->issued_at->toIso8601String(), 'service_label' => $v->service_label,
            'reservation_code' => $v->reservation?->code, 'service_date' => $v->service_date->toDateString(), 'pax' => $v->pax, 'status' => $v->status,
            'status_label' => Voucher::STATUS_LABELS[$v->status], 'used_at' => $v->used_at?->toIso8601String(), 'used_via' => $v->used_via, 'lock_version' => $v->lock_version];
    }

    // ---------------------------------------------------------------- Pagos

    public function payables(Provider $provider): array
    {
        $rows = Payable::where('provider_id', $provider->id)->where('status', '<>', 'anulada')->with(['reservation:id,code', 'invoices'])->orderBy('due_date')->get();
        $today = $this->today();

        return [
            'data' => $rows->map(fn (Payable $p) => $this->payableRow($p, $today))->all(),
            'totals' => $this->balances($rows->whereNotIn('status', ['pagada'])),
            'without_invoice' => $rows->filter(fn (Payable $p) => $p->status !== Payable::PAID && ! $p->invoices->whereIn('status', SupplierInvoice::ACTIVE)->count())->count(),
            'payment_terms' => $provider->payment_terms,
        ];
    }

    public function payableRow(Payable $p, string $today): array
    {
        $invoice = $p->invoices->first();
        $active = $p->invoices->whereIn('status', SupplierInvoice::ACTIVE)->first();

        return ['id' => $p->id, 'code' => $p->code, 'reservation_code' => $p->reservation?->code, 'concept' => $p->concept, 'amount' => (string) $p->amount,
            'paid' => (string) $p->paid_amount, 'balance' => $p->balance(), 'currency' => $p->currency, 'due_date' => $p->due_date->toDateString(),
            'days_to_due' => $this->daysTo($p->due_date), 'status' => $p->status,
            'status_label' => PayableService::supplierLabel($p, $today), 'invoice' => $invoice ? self::invoiceRow($invoice) : null,
            'can_upload' => $p->status !== Payable::PAID && ! $active];
    }

    public static function invoiceRow(SupplierInvoice $i): array
    {
        return ['id' => $i->id, 'number' => $i->number, 'issue_date' => $i->issue_date->toDateString(), 'amount' => (string) $i->amount, 'currency' => $i->currency,
            'amount_difference' => (string) $i->amount_difference, 'file_name' => $i->original_file_name, 'status' => $i->status,
            'status_label' => SupplierInvoice::STATUS_LABELS[$i->status], 'uploaded_at' => $i->uploaded_at->toIso8601String(), 'validation_note' => $i->validation_note];
    }

    /** SUP-BR-44: saldo por moneda, sin conversión. @return array<string, string> */
    private function balances(Collection $payables): array
    {
        return $payables->groupBy('currency')->map(fn ($g) => number_format($g->sum(fn (Payable $p) => (float) $p->balance()), 2, '.', ''))->sortKeys()->all();
    }

    // ---------------------------------------------------------------- Incidencias

    /** SUP-BR-50: del proveedor, reportadas por sus usuarios o de tipo Proveedor en reservas con sus servicios. */
    public function incidents(Provider $provider): Builder
    {
        return Incident::where(fn ($q) => $q->where('provider_id', $provider->id)
            ->orWhereIn('reported_by', User::where('provider_id', $provider->id)->select('id'))
            ->orWhere(fn ($w) => $w->where('type', 'Proveedor')->whereIn('reservation_id', BookedService::where('provider_id', $provider->id)->select('reservation_id'))));
    }

    public function incidentRows(Provider $provider, ?string $status): array
    {
        return $this->incidents($provider)->when($status, fn ($q) => $q->where('status', $status))->with('reservation:id,code')->latest()->limit(200)->get()
            ->map(fn (Incident $i) => $this->incidentRow($i))->all();
    }

    public function incident(Provider $provider, string $id): Incident
    {
        return $this->incidents($provider)->where(fn ($q) => Str::isUuid($id) ? $q->whereKey($id) : $q->where('code', $id))->first() ?? throw ApiException::notFound();
    }

    public function incidentRow(Incident $i): array
    {
        $last = IncidentLog::where('incident_id', $i->id)->where('visibility', 'proveedor')->max('created_at');

        return ['id' => $i->id, 'code' => $i->code, 'title' => $i->title, 'type' => $i->type, 'reservation_code' => $i->reservation?->code,
            'severity' => $i->severity, 'severity_label' => Incident::SEVERITY_LABELS[$i->severity], 'status' => $i->status,
            'status_label' => Incident::STATUS_LABELS[$i->status], 'created_at' => $i->created_at->toIso8601String(),
            'updated_at' => ($last ? Carbon::parse($last) : $i->updated_at)->toIso8601String(), 'can_comment' => $i->isActive()];
    }

    /** SUP-BR-53: logs visibles; autores internos como "Operaciones {agencia}". */
    public function incidentDetail(Provider $provider, Incident $i): array
    {
        $agency = 'Operaciones '.($this->tenant->tenant()?->trade_name ?? $this->tenant->tenant()?->legal_name);

        return $this->incidentRow($i) + ['description' => $i->description,
            'logs' => IncidentLog::where('incident_id', $i->id)->where('visibility', 'proveedor')->with('user:id,name,provider_id')->orderBy('created_at')->get()
                ->map(fn (IncidentLog $l) => ['kind' => $l->kind, 'message' => $l->message, 'at' => Carbon::parse($l->created_at)->toIso8601String(),
                    'author' => $l->user ? ($l->user->provider_id === $provider->id ? $l->user->name : $agency) : 'Sistema'])->all()];
    }

    /** Servicios propios elegibles para una nueva incidencia (SUP-BR-51). */
    public function incidentServices(Provider $provider): array
    {
        return $this->services($provider)->whereNotIn('status', [BookedService::CANCELLED, BookedService::REJECTED])
            ->whereDate('service_date', '>=', $this->clock->today()->subDays(7)->toDateString())->with('reservation:id,code')->orderBy('service_date')->limit(200)->get()
            ->map(fn (BookedService $s) => ['id' => $s->id, 'label' => "{$s->service_date->toDateString()} · {$s->name} · {$s->reservation?->code}"])->all();
    }

    // ---------------------------------------------------------------- Contrato y tarifas

    public function contract(Provider $provider): array
    {
        $days = $this->daysTo($provider->contract_end);
        $tenant = $this->tenant->tenant();
        $contact = fn (string $label, ?User $u, ?string $phone = null) => ['label' => $label, 'name' => $u?->name, 'email' => $u?->email, 'phone' => $phone ?? $u?->phone];
        $ops = CatalogRecipients::operations()->first();

        return [
            'provider' => ['ref' => $provider->ref, 'name' => $provider->name, 'ruc' => $provider->ruc, 'type' => $provider->type, 'stars' => $provider->stars,
                'city' => $provider->city, 'contract_start' => $provider->contract_start->toDateString(), 'contract_end' => $provider->contract_end->toDateString(),
                'status' => $provider->effectiveStatus(), 'currency' => $provider->currency, 'payment_terms' => $provider->payment_terms],
            'alert' => match (true) {
                $days < 0 => ['tone' => 'danger', 'message' => 'Su contrato está vencido: la agencia no puede solicitar nuevos servicios hasta renovarlo.'],
                $days < 60 => ['tone' => 'warning', 'message' => "Su contrato vence en {$days} día(s) ({$provider->contract_end->format('d/m/Y')}). Coordine la renovación con la agencia."],
                default => null,
            },
            'billing' => ['legal_name' => $tenant?->legal_name, 'ruc' => $tenant?->ruc, 'trade_name' => $tenant?->trade_name],
            'contacts' => [
                'provider' => [['label' => 'Contacto principal', 'name' => $provider->contact_name, 'email' => $provider->email, 'phone' => $provider->phone],
                    ...$provider->contacts()->orderBy('name')->get()->map(fn ($c) => ['label' => $c->role ?? 'Contacto', 'name' => $c->name, 'email' => $c->email, 'phone' => $c->phone])->all()],
                'agency' => [$contact('Operaciones', $ops), $contact('Pagos', CatalogRecipients::role('finance')->first()),
                    $contact('Emergencias 24 h', $ops, data_get($tenant?->settings, 'operations.emergency_phone') ?? $ops?->phone)],
            ],
        ];
    }

    /** SUP-BR-63: tarifas netas sin IGV; la próxima vigencia aprobada se muestra aparte. */
    public function rates(Provider $provider): array
    {
        $pending = RateRequest::where('provider_id', $provider->id)->where('status', RateRequest::PENDING)->pluck('code', 'service_id');

        return SupplierService::where('provider_id', $provider->id)->orderBy('name')->get()->map(function (SupplierService $s) use ($pending) {
            $next = ServiceRate::where('service_id', $s->id)->whereNull('applied_at')->orderBy('valid_from')->first();

            return ['id' => $s->id, 'ref' => $s->ref, 'name' => $s->name, 'type' => $s->type, 'unit' => $s->unit, 'cost' => (string) $s->cost, 'currency' => $s->currency,
                'valid_from' => $s->valid_from->toDateString(), 'valid_to' => $s->valid_to->toDateString(), 'status' => $s->effectiveStatus(),
                'pending_request' => $pending[$s->id] ?? null, 'can_request' => ! isset($pending[$s->id]) && $s->effectiveStatus() === 'activo',
                'next_rate' => $next ? ['cost' => (string) $next->cost, 'currency' => $next->currency, 'valid_from' => $next->valid_from->toDateString()] : null];
        })->all();
    }

    public function rateRequests(Provider $provider): array
    {
        return RateRequest::where('provider_id', $provider->id)->with('service:id,name')->latest('requested_at')->get()->map(fn (RateRequest $r) => self::rateRequestRow($r))->all();
    }

    public static function rateRequestRow(RateRequest $r): array
    {
        return ['id' => $r->id, 'code' => $r->code, 'service' => $r->service?->name, 'service_id' => $r->service_id, 'current_cost' => (string) $r->current_cost,
            'proposed_cost' => (string) $r->proposed_cost, 'currency' => $r->currency, 'effective_from' => $r->effective_from->toDateString(),
            'reason_type' => $r->reason_type, 'reason_detail' => $r->reason_detail, 'status' => $r->status, 'status_label' => RateRequest::STATUS_LABELS[$r->status],
            'requested_at' => $r->requested_at->toIso8601String(), 'reviewed_at' => $r->reviewed_at?->toIso8601String(), 'review_note' => $r->review_note];
    }

    /** Días calendario desde hoy (zona de la agencia) hasta la fecha. */
    private function daysTo(\DateTimeInterface $date): int
    {
        return (int) round(Carbon::parse($this->today(), 'UTC')->diffInDays(Carbon::parse($date->format('Y-m-d'), 'UTC'), false));
    }

    private function today(): string
    {
        return $this->clock->today()->toDateString();
    }
}
