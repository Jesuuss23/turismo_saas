<?php

namespace App\Http\Resources\Ops;

use App\Http\Resources\Crm\CrmPresenter;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\Passenger;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Departure;
use App\Models\Iam\User;
use App\Models\Ops\Assignment;
use App\Models\Ops\Driver;
use App\Models\Ops\Evidence;
use App\Models\Ops\Guide;
use App\Models\Ops\Incident;
use App\Models\Ops\IncidentLog;
use App\Models\Ops\Order;
use App\Models\Ops\RoomingList;
use App\Models\Ops\RoomingRoom;
use App\Models\Ops\SupplierRequest;
use App\Models\Ops\Vehicle;
use App\Models\Ops\Voucher;
use App\Models\Sys\NotifMessage;
use App\Services\Crm\CrmClock;
use App\Services\Ops\OpsAccess;
use App\Services\Ops\OrderService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Operaciones por rol (§7): Operaciones ve costos de proveedor, nunca precio de venta ni margen (salvo permisos de
 * rentabilidad en `cost-summary`). Botones sin permiso llegan deshabilitados con "Requiere permiso x.y" (OPS-BR-91).
 */
class OpsPresenter
{
    public const ALERT_TAGS = [
        'Dieta/alergia' => ['vegetar', 'vegan', 'alerg', 'celíac', 'celiac', 'sin gluten', 'intoleran', 'diabét'],
        'Celebración' => ['aniversario', 'cumpleaños', 'luna de miel', 'celebra', 'boda'],
        'Accesibilidad' => ['silla de ruedas', 'movilidad', 'accesib', 'bastón', 'discapacidad'],
    ];

    public function __construct(
        public readonly CrmPresenter $users,
        private readonly OpsAccess $access,
        private readonly CrmClock $clock,
    ) {}

    public function orderRow(Order $o): array
    {
        $assignments = $o->relationLoaded('assignments') ? $o->assignments : $o->assignments()->get();
        $total = $assignments->count();
        $withResources = $assignments->filter(fn (Assignment $a) => $a->status !== Assignment::PENDING)->count();
        $r = $o->reservation;

        return [
            'id' => $o->id, 'code' => $o->code, 'title' => $o->title, 'status' => $o->status, 'status_label' => Order::STATUS_LABELS[$o->status],
            'reservation' => $r ? ['id' => $r->id, 'code' => $r->code, 'status' => $r->status, 'client' => $r->client?->name] : null,
            'coordinator' => $this->users->user($o->coordinator_id), 'date_from' => $o->date_from->toDateString(), 'date_to' => $o->date_to->toDateString(),
            'pax' => $r ? Passenger::where('reservation_id', $r->id)->count() : 0,
            'assignments_total' => $total, 'assignments_done' => $withResources, 'assigned_pct' => $total ? (int) round($withResources / $total * 100) : 100,
            'incidents_open' => $o->incidents()->whereIn('status', Incident::ACTIVE)->count(),
            'critical_open' => $o->incidents()->whereIn('status', Incident::ACTIVE)->where('severity', 'critica')->count(),
            'lock_version' => $o->lock_version,
        ];
    }

    public function orderDetail(Order $o, User $user, array $closeChecks): array
    {
        $r = $o->reservation;
        $services = BookedService::where('reservation_id', $o->reservation_id)->with('provider')->orderBy('service_date')->orderBy('service_time')->get();
        $assignments = $o->assignments()->with(['guide', 'driver', 'vehicle'])->get()->keyBy('reservation_service_id');
        $vouchers = Voucher::where('reservation_id', $o->reservation_id)->with('provider:id,name')->orderByDesc('issued_at')->get();
        $active = $vouchers->where('status', Voucher::ISSUED)->keyBy('reservation_service_id');
        $requests = SupplierRequest::whereIn('reservation_service_id', $services->pluck('id'))->orderByDesc('requested_at')->get()->groupBy('reservation_service_id');
        $passengers = Passenger::where('reservation_id', $o->reservation_id)->orderBy('seq')->get();
        $incidents = $o->incidents()->get();
        $evidences = Evidence::where(fn ($q) => $q->where('order_id', $o->id)->orWhereIn('incident_id', $incidents->pluck('id')))->whereNull('removed_at')->with('uploader:id,name')->orderByDesc('captured_at')->get();

        return $this->orderRow($o) + [
            'reservation_detail' => $r ? ['code' => $r->code, 'title' => $r->title, 'status' => $r->status, 'status_label' => Reservation::STATUS_LABELS[$r->status] ?? $r->status,
                'client' => $r->client?->name, 'notes' => $r->notes, 'channel' => $r->channel, 'owner' => $this->users->user($r->owner_id)] : null,
            'kpis' => [
                'services' => $services->whereNotIn('status', [BookedService::CANCELLED, BookedService::REJECTED])->count(),
                'supplier_confirmed' => $services->whereIn('status', [BookedService::CONFIRMED, BookedService::IN_PROGRESS, BookedService::COMPLETED])->count(),
                'with_resources' => $assignments->where('status', '<>', Assignment::PENDING)->count(), 'assignments' => $assignments->count(),
                'vouchers_active' => $active->count(), 'incidents_open' => $incidents->whereIn('status', Incident::ACTIVE)->count(), 'evidences' => $evidences->count(),
            ],
            'services' => $services->map(fn (BookedService $s) => [
                'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'date' => $s->service_date->toDateString(),
                'time' => $s->service_time ? substr((string) $s->service_time, 0, 5) : null, 'kind' => $s->service_kind, 'requires_assignment' => $s->requires_assignment,
                'status' => $s->status, 'status_label' => BookedService::STATUS_LABELS[$s->status] ?? $s->status, 'provider' => $s->provider ? ['id' => $s->provider->id, 'name' => $s->provider->name, 'usable' => $s->provider->isUsable()] : null,
                'confirmation_code' => $s->confirmation_code, 'cost' => (string) $s->cost_amount, 'reject_reason' => $s->reject_reason,
                'assignment' => isset($assignments[$s->id]) ? $this->assignment($assignments[$s->id]) : null,
                'voucher' => isset($active[$s->id]) ? ['id' => $active[$s->id]->id, 'code' => $active[$s->id]->code, 'version' => $active[$s->id]->version] : null,
                'requests' => ($requests[$s->id] ?? collect())->take(3)->map(fn (SupplierRequest $q) => ['status' => $q->status, 'channel' => $q->channel,
                    'requested_at' => $q->requested_at->toIso8601String(), 'responded_at' => $q->responded_at?->toIso8601String(),
                    'confirmation_code' => $q->confirmation_code, 'rejection_reason' => $q->rejection_reason])->values()->all(),
                'actions' => $this->serviceActions($s, $o, $user, isset($assignments[$s->id]), isset($active[$s->id])),
            ])->values()->all(),
            'tasks' => $o->tasks()->get()->map(fn ($t) => ['id' => $t->id, 'title' => $t->title, 'done' => $t->done, 'done_by' => $this->users->user($t->done_by),
                'done_at' => $t->done_at?->toIso8601String()])->all(),
            'passengers' => $passengers->map(fn (Passenger $p) => $this->passenger($p))->all(),
            'alert_tags' => $this->alertTags($passengers, $r?->notes),
            'rooming' => RoomingList::where('reservation_id', $o->reservation_id)->with('hotel:id,name')->withCount('rooms')->get()
                ->map(fn (RoomingList $l) => $this->roomingRow($l))->all(),
            'vouchers' => $vouchers->map(fn (Voucher $v) => $this->voucherRow($v))->all(),
            'incidents' => $incidents->map(fn (Incident $i) => $this->incidentRow($i))->all(),
            'evidences' => $evidences->map(fn (Evidence $e) => $this->evidence($e))->all(),
            'close' => $closeChecks,
            'actions' => $this->orderActions($o, $user, $closeChecks),
            'can' => [
                'assign' => $this->access->can($user, OpsAccess::ASSIGN), 'vouchers' => $this->access->can($user, OpsAccess::VOUCHERS),
                'incidents' => $this->access->can($user, OpsAccess::INCIDENTS), 'close' => $this->access->can($user, OpsAccess::CLOSE),
                'rooming' => $this->access->can($user, OpsAccess::ROOMING), 'see_margin' => $this->access->seeMargin($user),
            ],
        ];
    }

    public function orderActions(Order $o, User $user, array $close): array
    {
        $out = [];
        $add = function (string $key, string $label, string $permission, ?string $blocked = null, string $group = 'primary') use (&$out, $user) {
            $reason = $this->access->reason($user, $permission);
            $out[] = ['key' => $key, 'label' => $label, 'enabled' => $reason === null && $blocked === null, 'reason' => $reason ?? $blocked, 'group' => $group];
        };
        $active = $o->isActive();
        if ($active) {
            match ($o->status) {
                Order::PLANNED => $add('to_assignment', 'Pasar a asignación', OpsAccess::ASSIGN),
                Order::PENDING_ASSIGNMENT => $add('mark_assigned', 'Marcar como asignada', OpsAccess::ASSIGN,
                    ($n = $o->assignments()->where('status', Assignment::PENDING)->count()) ? "Hay {$n} servicio(s) sin recursos" : null),
                Order::ASSIGNED => $add('confirm', 'Confirmar operación', OpsAccess::ASSIGN, ($issues = app(OrderService::class)->confirmIssues($o)) ? implode(' · ', $issues) : null),
                Order::CONFIRMED => $add('start', 'Iniciar ejecución', OpsAccess::ASSIGN),
                default => null,
            };
            $add('close', 'Ir a cierre', OpsAccess::CLOSE, $o->status === Order::IN_EXECUTION ? null : 'El cierre se habilita con la orden en ejecución', $o->status === Order::IN_EXECUTION ? 'primary' : 'more');
            $add('coordinator', 'Cambiar coordinador', OpsAccess::ASSIGN, null, 'more');
            $add('new_incident', 'Nueva incidencia', OpsAccess::INCIDENTS, null, 'more');
        }
        $add('view_reservation', 'Ver reserva', 'reservations.view', null, 'more');
        $add('cost_summary', 'Resumen de costos', OpsAccess::VIEW, null, 'more');

        return $out;
    }

    /** Menú "…" del servicio (§7): solo acciones con permiso; las bloqueadas llegan con motivo. */
    public function serviceActions(BookedService $s, Order $o, User $user, bool $hasAssignment, bool $hasVoucher): array
    {
        $out = [];
        $add = function (string $key, string $label, string $permission, ?string $blocked = null) use (&$out, $user) {
            $reason = $this->access->reason($user, $permission);
            $out[] = ['key' => $key, 'label' => $label, 'enabled' => $reason === null && $blocked === null, 'reason' => $reason ?? $blocked];
        };
        if (! $o->isActive() || $s->status === BookedService::CANCELLED) {
            return [];
        }
        if ($s->provider_id && $s->status === BookedService::PENDING) {
            $add('supplier_request', 'Enviar solicitud al proveedor', OpsAccess::ASSIGN);
        }
        if ($s->provider_id && in_array($s->status, [BookedService::PENDING, BookedService::REQUESTED], true) || ($s->status === BookedService::REJECTED && ! $s->replaced_by_id)) {
            $add('supplier_confirm', 'Registrar confirmación del proveedor', OpsAccess::ASSIGN);
        }
        if ($s->status === BookedService::REQUESTED) {
            $add('supplier_reject', 'Registrar rechazo', OpsAccess::ASSIGN);
        }
        if (! $hasAssignment && $s->provider_id && $s->isActive()) {
            $add('ensure_assignment', 'Agregar asignación de recursos', OpsAccess::ASSIGN);
        }
        if (in_array($s->status, [BookedService::CONFIRMED, BookedService::IN_PROGRESS], true)) {
            $add('complete_service', 'Marcar servicio completado', OpsAccess::ASSIGN,
                $s->service_date->toDateString() > $this->clock->today()->toDateString() ? 'No puede completarse antes de su fecha (PROC-036)' : null);
        }
        if (in_array($s->status, [BookedService::CONFIRMED, BookedService::IN_PROGRESS, BookedService::COMPLETED], true) && ! $hasVoucher) {
            $add('issue_voucher', 'Emitir voucher', OpsAccess::VOUCHERS);
        }

        return $out;
    }

    public function assignment(Assignment $a): array
    {
        return [
            'id' => $a->id, 'service_id' => $a->reservation_service_id, 'label' => $a->service_label, 'date' => $a->service_date->toDateString(),
            'time' => $a->service_time ? substr((string) $a->service_time, 0, 5) : null, 'status' => $a->status, 'status_label' => Assignment::STATUS_LABELS[$a->status],
            'guide' => $a->guide ? ['id' => $a->guide->id, 'code' => $a->guide->code, 'name' => $a->guide->full_name, 'phone' => $a->guide->phone] : null,
            'driver' => $a->driver ? ['id' => $a->driver->id, 'code' => $a->driver->code, 'name' => $a->driver->full_name, 'phone' => $a->driver->phone] : null,
            'vehicle' => $a->vehicle ? ['id' => $a->vehicle->id, 'code' => $a->vehicle->code, 'name' => $a->vehicle->label(), 'capacity' => $a->vehicle->capacity] : null,
            'confirmed_at' => $a->confirmed_at?->toIso8601String(), 'confirmed_via' => $a->confirmed_via, 'started_at' => $a->started_at?->toIso8601String(),
            'first_checkin_at' => $a->first_checkin_at?->toIso8601String(), 'finished_at' => $a->finished_at?->toIso8601String(),
            'checkins' => $a->checkins()->count(), 'no_show' => count((array) $a->no_show_passenger_ids), 'warnings_ack' => $a->warnings_ack,
            'lock_version' => $a->lock_version,
        ];
    }

    /** Tarjeta y detalle de la app del operador (§2.1). */
    public function mobileAssignment(Assignment $a, string $type): array
    {
        $order = $a->order;
        $r = $order?->reservation;
        $service = $a->service;
        $passengers = $r ? Passenger::where('reservation_id', $r->id)->orderBy('seq')->get() : collect();
        $checkins = $a->checkins()->whereNull('undone_at')->get()->keyBy('passenger_id');
        $partner = $type === 'guide' ? $a->driver : $a->guide;
        $departure = $service?->departure_id ? Departure::find($service->departure_id) : null;
        $evidences = Evidence::where('reservation_service_id', $a->reservation_service_id)->whereNull('removed_at')->orderByDesc('captured_at')->get();
        $steps = ['Asignado', 'Confirmado', 'Inicio', 'Check-in', 'Evidencia', 'Fin'];
        $step = match ($a->status) {
            Assignment::PENDING, Assignment::ASSIGNED => 0,
            Assignment::CONFIRMED => 1,
            Assignment::IN_PROGRESS => $evidences->isNotEmpty() ? 4 : ($checkins->isNotEmpty() ? 3 : 2),
            Assignment::COMPLETED => 5,
        };

        return [
            'id' => $a->id, 'order_code' => $order?->code, 'reservation_code' => $r?->code, 'client' => $r?->client?->name, 'title' => $order?->title,
            'label' => $a->service_label, 'date' => $a->service_date->toDateString(), 'time' => $a->service_time ? substr((string) $a->service_time, 0, 5) : null,
            'status' => $a->status, 'status_label' => Assignment::STATUS_LABELS[$a->status], 'steps' => $steps, 'step' => $step,
            'meeting_point' => $departure?->meeting_point ?? 'Lobby del hotel del pasajero', 'provider' => $service?->provider?->name,
            'vehicle' => $a->vehicle?->label(), 'role' => $type === 'guide' ? 'Guía' : 'Conductor',
            'partner' => $partner ? ['name' => $partner->full_name, 'phone' => $partner->phone, 'role' => $type === 'guide' ? 'Conductor' : 'Guía'] : null,
            'coordinator' => $order?->coordinator ? ['name' => $order->coordinator->name, 'phone' => $order->coordinator->phone] : null,
            'notes' => $r?->notes, 'alert_tags' => $this->alertTags($passengers, $r?->notes),
            'diets' => $passengers->whereNotNull('diet_notes')->map(fn ($p) => "{$p->full_name}: {$p->diet_notes}")->values()->all(),
            'passengers' => $passengers->map(fn (Passenger $p) => [
                'id' => $p->id, 'name' => $p->full_name, 'type' => $p->pax_type, 'lead' => $p->is_lead, 'diet' => $p->diet_notes,
                'doc' => $p->doc_number ? ($p->doc_type.' ••••'.substr((string) $p->doc_number, -4)) : null,
                'checked_at' => $checkins->get($p->id)?->checked_at?->toIso8601String(),
            ])->values()->all(),
            'incidents' => $order ? $order->incidents()->whereIn('status', Incident::ACTIVE)->get()->map(fn ($i) => ['code' => $i->code, 'title' => $i->title,
                'severity' => $i->severity])->all() : [],
            'evidences' => $evidences->map(fn (Evidence $e) => ['id' => $e->id, 'file_name' => $e->file_name, 'captured_at' => $e->captured_at->toIso8601String(),
                'offline' => $e->captured_offline])->all(),
            'started_at' => $a->started_at?->toIso8601String(), 'finished_at' => $a->finished_at?->toIso8601String(),
            'no_show' => count((array) $a->no_show_passenger_ids), 'order_status' => $order?->status,
        ];
    }

    public function resourceProfile(string $type, Model $resource): array
    {
        return [
            'type' => $type, 'id' => $resource->id, 'code' => $resource->code, 'name' => $resource->full_name, 'phone' => $resource->phone,
            'license_number' => $resource->license_number, 'license_class' => $resource->license_class ?? null,
            'license_expires_on' => $resource->license_expires_on?->toDateString(), 'languages' => $resource->languages ?? null, 'status' => $resource->status,
        ];
    }

    /** Tarjeta de "Asignaciones y recursos" (§2) con estado derivado de la ocupación (§12-7). */
    public function resourceCard(string $type, Model $resource, Collection $occupations, ?User $user = null): array
    {
        $today = $this->clock->today()->toDateString();
        $soon = $this->clock->today()->addDays(30)->toDateString();
        $busyToday = $occupations->contains(fn ($o) => $o['date'] === $today);
        $status = $resource->status === 'disponible' && $busyToday ? 'asignado' : $resource->status;
        $doc = match (true) {
            $resource instanceof Driver => ['label' => 'Licencia', 'expires_on' => $resource->license_expires_on->toDateString()],
            $resource instanceof Vehicle => ['label' => 'SOAT', 'expires_on' => $resource->soat_expires_on->toDateString()],
            default => $resource->license_expires_on ? ['label' => 'Carné', 'expires_on' => $resource->license_expires_on->toDateString()] : null,
        };
        if ($doc) {
            $doc['alert'] = $doc['expires_on'] < $today ? 'vencido' : ($doc['expires_on'] <= $soon ? 'por_vencer' : null);
        }

        return [
            'type' => $type, 'id' => $resource->id, 'code' => $resource->code, 'name' => $resource instanceof Vehicle ? $resource->label() : $resource->full_name,
            'status' => $status, 'manual_status' => $resource->status, 'phone' => $resource->phone ?? null,
            'languages' => $resource instanceof Guide ? $resource->languages : null, 'rating' => $resource instanceof Guide ? (string) $resource->rating : null,
            'capacity' => $resource instanceof Vehicle ? $resource->capacity : null, 'license_class' => $resource instanceof Driver ? $resource->license_class : null,
            'document' => $doc, 'occupations' => $occupations->values()->all(),
            'statuses' => $resource::STATUSES,
        ];
    }

    public function passenger(Passenger $p): array
    {
        return [
            'id' => $p->id, 'seq' => $p->seq, 'name' => $p->full_name, 'type' => $p->pax_type, 'age' => $p->age, 'lead' => $p->is_lead,
            'relationship' => $p->relationship, 'nationality' => $p->nationality, 'diet' => $p->diet_notes, 'phone' => $p->phone,
            'doc' => $p->doc_number ? "{$p->doc_type} {$p->doc_number}" : null, 'placeholder' => $p->is_placeholder,
        ];
    }

    /** OPS-V21-04: etiquetas operativas derivadas de dietas y comentarios (solo el texto del cliente, sin diagnósticos). */
    public function alertTags(Collection $passengers, ?string $notes): array
    {
        $text = mb_strtolower($passengers->pluck('diet_notes')->filter()->implode(' ').' '.$notes);
        $tags = [];
        foreach (self::ALERT_TAGS as $tag => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    $tags[] = $tag;
                    break;
                }
            }
        }
        if ($passengers->contains('pax_type', 'INF')) {
            $tags[] = 'Infante';
        }

        return $tags;
    }

    public function roomingRow(RoomingList $l): array
    {
        return [
            'id' => $l->id, 'reservation' => $l->reservation ? ['code' => $l->reservation->code, 'title' => $l->reservation->title] : null,
            'hotel' => $l->hotel ? ['id' => $l->hotel->id, 'name' => $l->hotel->name] : null, 'check_in' => $l->check_in->toDateString(),
            'check_out' => $l->check_out->toDateString(), 'status' => $l->status, 'status_label' => RoomingList::STATUS_LABELS[$l->status],
            'rooms_count' => $l->rooms_count ?? $l->rooms()->count(), 'sent_at' => $l->sent_at?->toIso8601String(), 'sent_version' => $l->sent_version,
            'hotel_ack_at' => $l->hotel_ack_at?->toIso8601String(), 'hotel_confirmed_at' => $l->hotel_confirmed_at?->toIso8601String(), 'lock_version' => $l->lock_version,
        ];
    }

    public function roomingDetail(RoomingList $l, array $validation, User $user): array
    {
        $passengers = Passenger::where('reservation_id', $l->reservation_id)->orderBy('seq')->get()->keyBy('id');

        return $this->roomingRow($l) + [
            'rooms' => $l->rooms()->with('guests')->get()->map(fn (RoomingRoom $room) => [
                'id' => $room->id, 'room_number' => $room->room_number, 'room_type' => $room->room_type, 'capacity' => $room->capacity, 'status' => $room->status,
                'hotel_note' => $room->hotel_note,
                'guests' => $room->guests->map(fn ($g) => ['passenger_id' => $g->passenger_id, 'name' => $passengers[$g->passenger_id]->full_name ?? '—',
                    'type' => $passengers[$g->passenger_id]->pax_type ?? null])->values()->all(),
                'occupancy' => $room->guests->filter(fn ($g) => ($passengers[$g->passenger_id]->pax_type ?? null) !== 'INF')->count(),
                'infants' => $room->guests->filter(fn ($g) => ($passengers[$g->passenger_id]->pax_type ?? null) === 'INF')->count(),
            ])->all(),
            'passengers' => $passengers->values()->map(fn (Passenger $p) => ['id' => $p->id, 'name' => $p->full_name, 'type' => $p->pax_type])->all(),
            'validation' => $validation,
            'can_modify' => $this->access->can($user, OpsAccess::ROOMING),
        ];
    }

    public function voucherRow(Voucher $v): array
    {
        return [
            'id' => $v->id, 'code' => $v->code, 'status' => $v->status, 'status_label' => Voucher::STATUS_LABELS[$v->status], 'version' => $v->version,
            'service_label' => $v->service_label, 'service_date' => $v->service_date->toDateString(), 'pax' => $v->pax,
            'provider' => $v->provider ? ['id' => $v->provider->id, 'name' => $v->provider->name] : null,
            'reservation' => $v->relationLoaded('reservation') && $v->reservation ? ['code' => $v->reservation->code, 'client' => $v->reservation->client?->name] : null,
            'issued_at' => $v->issued_at->toIso8601String(), 'used_at' => $v->used_at?->toIso8601String(), 'used_via' => $v->used_via,
            'void_reason' => $v->void_reason, 'origin' => $v->origin, 'lock_version' => $v->lock_version,
        ];
    }

    public function voucherDetail(Voucher $v, array $snapshot, User $user): array
    {
        $issued = $v->status === Voucher::ISSUED;
        $reason = $this->access->reason($user, OpsAccess::VOUCHERS);
        $blocked = $issued ? null : 'Solo vouchers vigentes (emitidos)';

        return $this->voucherRow($v->loadMissing('reservation.client', 'provider')) + [
            'qr_token' => $v->qr_token, 'note' => $v->note, 'document' => $snapshot,
            'versions' => $v->versions()->get()->map(fn ($ver) => ['version' => $ver->version, 'issued_at' => $ver->issued_at->toIso8601String(),
                'issued_by' => $this->users->user($ver->issued_by), 'reason' => $ver->reason])->all(),
            'notifications' => NotifMessage::where('related_type', 'ops_voucher')->where('related_id', $v->id)->orderByDesc('created_at')->limit(20)->get()
                ->map(fn (NotifMessage $n) => ['event' => $n->event, 'channel' => $n->channel, 'recipient' => $n->recipient, 'status' => $n->status, 'at' => $n->created_at->toIso8601String()])->all(),
            'actions' => collect([['resend', 'Reenviar'], ['new_version', 'Nueva versión'], ['redeem', 'Marcar utilizado'], ['void', 'Anular']])
                ->map(fn ($a) => ['key' => $a[0], 'label' => $a[1], 'enabled' => $reason === null && $blocked === null, 'reason' => $reason ?? $blocked])->all(),
            'can_issue' => $reason === null,
        ];
    }

    public function incidentRow(Incident $i): array
    {
        $now = now();
        $elapsed = max(0, $i->created_at->diffInMinutes($now));
        $total = max(1, $i->sla_hours * 60);

        return [
            'id' => $i->id, 'code' => $i->code, 'title' => $i->title, 'type' => $i->type, 'severity' => $i->severity,
            'severity_label' => Incident::SEVERITY_LABELS[$i->severity], 'status' => $i->status, 'status_label' => Incident::STATUS_LABELS[$i->status],
            'order' => $i->order_id ? ['id' => $i->order_id, 'code' => $i->order?->code] : null, 'assignee' => $this->users->user($i->assignee_id),
            'reported_by' => $this->users->user($i->reported_by), 'source' => $i->source, 'created_at' => $i->created_at->toIso8601String(),
            'sla_hours' => $i->sla_hours, 'sla_due_at' => $i->sla_due_at->toIso8601String(),
            'sla_pct' => $i->isActive() ? min(100, (int) round($elapsed / $total * 100)) : null,
            'sla_breached' => $i->isActive() && $i->sla_due_at->lt($now), 'related' => $i->related_incident_id, 'lock_version' => $i->lock_version,
        ];
    }

    public function incidentDetail(Incident $i, User $user): array
    {
        $step = match (true) {
            $i->status === Incident::CLOSED => 8,
            $i->resolution && $i->evidences()->exists() => 7,
            (bool) $i->resolution => 6,
            (bool) $i->assignee_id => 5,
            default => 4,
        };
        $reason = $this->access->reason($user, OpsAccess::INCIDENTS);
        $add = fn (string $key, string $label, ?string $blocked = null) => ['key' => $key, 'label' => $label, 'enabled' => $reason === null && $blocked === null, 'reason' => $reason ?? $blocked];
        $editable = $i->isActive() ? null : 'La incidencia está '.mb_strtolower(Incident::STATUS_LABELS[$i->status]);

        return $this->incidentRow($i) + [
            'description' => $i->description, 'resolution' => $i->resolution, 'resolved_at' => $i->resolved_at?->toIso8601String(),
            'closed_at' => $i->closed_at?->toIso8601String(), 'step' => $step, 'flow' => Incident::FLOW,
            'reservation' => $i->reservation ? ['code' => $i->reservation->code, 'title' => $i->reservation->title] : null,
            'service' => $i->service ? ['code' => $i->service->code, 'name' => $i->service->name] : null,
            'related_code' => $i->related_incident_id ? Incident::whereKey($i->related_incident_id)->value('code') : null,
            'logs' => $i->logs()->get()->map(fn (IncidentLog $l) => ['kind' => $l->kind, 'message' => $l->message, 'user' => $l->user_id ? $this->users->user($l->user_id) : ['name' => 'Sistema'],
                'visibility' => $l->visibility, 'at' => $l->created_at->toIso8601String()])->all(),
            'evidences' => $i->evidences()->with('uploader:id,name')->get()->map(fn (Evidence $e) => $this->evidence($e))->all(),
            'notifications' => NotifMessage::where('related_type', 'ops_incident')->where('related_id', $i->id)->orderByDesc('created_at')->limit(20)->get()
                ->map(fn (NotifMessage $n) => ['event' => $n->event, 'channel' => $n->channel, 'recipient' => $n->recipient, 'at' => $n->created_at->toIso8601String()])->all(),
            'actions' => array_values(array_filter([
                $i->status === Incident::OPEN ? $add('take', 'Pasar a en proceso') : null,
                $add('assign', 'Asignar', $editable), $add('reclassify', 'Reclasificar', $editable),
                $i->isActive() ? $add('resolve', 'Registrar resolución') : null,
                $i->status !== Incident::CLOSED ? $add('close', 'Cerrar incidencia') : null,
                $i->status === Incident::CLOSED ? $add('reopen', 'Reabrir') : null,
                $add('comment', 'Comentar'), $add('evidence', 'Adjuntar evidencia'),
            ])),
        ];
    }

    public function evidence(Evidence $e): array
    {
        return [
            'id' => $e->id, 'file_name' => $e->file_name, 'mime_type' => $e->mime_type, 'size' => $e->size_bytes, 'source' => $e->source,
            'service_id' => $e->reservation_service_id, 'incident_id' => $e->incident_id, 'captured_at' => $e->captured_at->toIso8601String(),
            'offline' => $e->captured_offline, 'uploaded_by' => $e->uploader ? ['id' => $e->uploader->id, 'name' => $e->uploader->name] : null, 'sha256' => $e->sha256,
        ];
    }
}
