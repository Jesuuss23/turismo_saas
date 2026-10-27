<?php

namespace App\Http\Resources\Bookings;

use App\Http\Resources\Crm\CrmPresenter;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\BookingChange;
use App\Models\Bookings\CancelRequest;
use App\Models\Bookings\Passenger;
use App\Models\Bookings\Reservation;
use App\Models\Finance\Payment;
use App\Models\Finance\Receivable;
use App\Models\Iam\User;
use App\Models\Ops\Order;
use App\Services\Bookings\BookingAccess;
use App\Services\Bookings\ReceivableService;
use App\Services\Crm\CrmClock;
use App\Services\Ops\OrderService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;

/**
 * Reserva 360 filtrada por rol (§7): lo que el rol no ve no se envía. Operaciones (`opsView`) recibe costos sin
 * total, saldo, precios ni margen; Contabilidad (`acc`) recibe montos sin costos.
 */
class ReservationPresenter
{
    public function __construct(
        private readonly BookingAccess $access,
        private readonly ReceivableService $receivables,
        private readonly CrmClock $clock,
        public readonly CrmPresenter $users,
    ) {}

    public function today(): CarbonInterface
    {
        return $this->clock->today();
    }

    /** @return array{see_margin: bool, ops_view: bool, acc: bool, see_cost: bool, see_price: bool} */
    public function view(User $user, ?Reservation $r = null): array
    {
        $margin = $this->access->seeMargin($user, $r);
        $ops = $this->access->opsView($user, $r);

        return ['see_margin' => $margin, 'ops_view' => $ops, 'acc' => ! $margin && ! $ops, 'see_cost' => $this->access->seeCost($user, $r), 'see_price' => $this->access->seePrice($user, $r)];
    }

    /** Fila de lista. Requiere `services` y `passengers_count` cargados (o los consulta). */
    public function row(Reservation $r, User $user): array
    {
        $v = $this->view($user, $r);
        $services = $r->relationLoaded('services') ? $r->services : $r->services()->get();
        $active = $services->filter(fn (BookedService $s) => $s->status !== BookedService::CANCELLED && $s->status !== BookedService::REJECTED);
        $cost = $active->reduce(fn (BigDecimal $sum, BookedService $s) => $sum->plus((string) $s->cost_amount), BigDecimal::zero());
        $price = $active->reduce(fn (BigDecimal $sum, BookedService $s) => $sum->plus((string) $s->price_amount), BigDecimal::zero());

        return [
            'id' => $r->id,
            'code' => $r->code,
            'title' => $r->title,
            'client' => $r->client ? ['id' => $r->client->id, 'code' => $r->client->code, 'name' => $r->client->name] : null,
            'owner' => $this->users->user($r->owner_id),
            'status' => $r->status,
            'status_label' => Reservation::STATUS_LABELS[$r->status] ?? $r->status,
            'channel' => $r->channel,
            'travel_from' => $r->travel_from->toDateString(),
            'travel_to' => $r->travel_to->toDateString(),
            'pax' => $r->passengers_count ?? $r->passengers()->count(),
            'currency' => $r->currency,
            'quote' => $r->quote_id ? ['code' => $r->quote?->code, 'version_no' => $r->quote_version_no] : null,
            'services_confirmed' => $active->whereIn('status', [BookedService::CONFIRMED, BookedService::COMPLETED])->count(),
            'services_total' => $active->count(),
            ...($v['see_price'] && ! $v['ops_view'] ? ['total' => (string) $r->total_amount, 'balance' => $this->balance($r)] : []),
            ...($v['see_cost'] && ! $v['acc'] ? ['provider_cost' => (string) $cost->toScale(2)] : []),
            ...($v['see_margin'] ? ['margin_pct' => $price->isZero() ? '0.0' : (string) $price->minus($cost)->multipliedBy(100)->dividedBy($price, 1, RoundingMode::HalfUp)] : []),
        ];
    }

    public function detail(Reservation $r, User $user, ?array $pendingRequest = null): array
    {
        $r->loadMissing(['client.primaryContact', 'quote', 'passengers', 'services.provider', 'services.product', 'changes', 'cancelRequests', 'cancellation', 'receivables', 'payments']);
        $v = $this->view($user, $r);
        $this->users->preloadUsers([$r->owner_id, ...$r->changes->pluck('requested_by'), ...$r->changes->pluck('decided_by'), ...$r->cancelRequests->pluck('requested_by')]);
        $pendingChange = $r->changes->firstWhere('status', 'pendiente');
        $pendingCancel = $r->cancelRequests->firstWhere('status', 'pendiente');
        $finance = ! $v['ops_view'];

        return array_merge($this->row($r, $user), [
            'client' => $r->client ? [
                'id' => $r->client->id, 'code' => $r->client->code, 'name' => $r->client->name, 'segment' => $r->client->segment_code,
                'email' => $r->client->email ?? $r->client->primaryContact?->email, 'phone' => $r->client->phone ?? $r->client->primaryContact?->phone,
            ] : null,
            'lock_version' => $r->lock_version,
            'snapshot_label' => $r->snapshot_label,
            'notes' => $r->notes,
            'policies' => array_values($r->policies_snapshot ?? []),
            'flow' => collect(Reservation::FLOW)->map(fn ($s) => ['status' => $s, 'label' => Reservation::STATUS_LABELS[$s]])->all(),
            'rescheduled_at' => $r->rescheduled_at,
            'cancelled_at' => $r->cancelled_at,
            'cancel_reason' => $r->cancel_reason,
            ...($finance ? ['penalty_amount' => $r->penalty_amount, 'refund_amount' => $r->refund_amount, 'paid' => $this->receivables->paid($r)] : []),
            'alerts' => $this->alerts($r, $pendingChange, $pendingCancel),
            'passengers' => $r->passengers->map(fn (Passenger $p) => $this->passenger($p))->all(),
            'services' => $r->services->map(fn (BookedService $s) => $this->service($s, $v))->all(),
            'receivables' => $finance ? $r->receivables->map(fn (Receivable $c) => $this->receivable($c))->all() : null,
            'payments' => $finance ? $r->payments->map(fn (Payment $p) => [
                'id' => $p->id, 'code' => $p->code, 'kind' => $p->kind, 'method' => $p->method, 'amount' => (string) $p->amount, 'currency' => $p->currency,
                'paid_on' => $p->paid_on->toDateString(), 'status' => $p->status, 'reference' => $p->reference,
                'receivable' => $p->receivable_id ? $r->receivables->firstWhere('id', $p->receivable_id)?->code : null,
            ])->all() : null,
            'changes' => $r->changes->map(fn (BookingChange $c) => [
                'id' => $c->id, 'type' => $c->type, 'type_label' => BookingChange::TYPES[$c->type], 'description' => $c->description, 'extra_pax' => $c->extra_pax,
                'service_date' => $c->service_date?->toDateString(), 'status' => $c->status,
                ...($finance ? ['delta_amount' => (string) $c->delta_amount, 'credit_amount' => $c->credit_amount] : []),
                'requested_by' => $this->users->user($c->requested_by), 'requested_at' => $c->requested_at,
                'decided_by' => $c->decided_by ? $this->users->user($c->decided_by) : null, 'decided_at' => $c->decided_at, 'decision_note' => $c->decision_note,
            ])->all(),
            'pending_change' => $pendingChange?->id,
            'cancel_request' => $pendingCancel ? ['id' => $pendingCancel->id, 'reason' => $pendingCancel->reason, 'requested_by' => $this->users->user($pendingCancel->requested_by), 'requested_at' => $pendingCancel->requested_at] : null,
            'cancellation' => $r->cancellation && $finance ? [
                'penalty' => (string) $r->cancellation->penalty_amount, 'refund' => (string) $r->cancellation->refund_amount, 'owed' => (string) $r->cancellation->owed_amount,
                'k' => (string) $r->cancellation->factor_k, 'lines' => $r->cancellation->lines, 'reason' => $r->cancellation->reason, 'at' => $r->cancellation->cancelled_at,
            ] : null,
            'view' => $v,
            'actions' => $this->actions($r, $user, $pendingCancel, $pendingChange),
            'can' => [
                'edit_passengers' => ! $r->isClosed() && $user->hasPermissionTo(BookingAccess::MODIFY),
                'manage_services' => ! $r->isClosed() && $user->hasPermissionTo(BookingAccess::MODIFY),
                'approve_changes' => $user->hasPermissionTo(BookingAccess::APPROVE_CHANGES),
                'register_payment' => $user->hasPermissionTo(BookingAccess::PAYMENTS),
                'process_cancel' => $user->hasPermissionTo(BookingAccess::CANCEL),
            ],
        ]);
    }

    public function passenger(Passenger $p): array
    {
        return [
            'id' => $p->id, 'seq' => $p->seq, 'full_name' => $p->full_name, 'doc_type' => $p->doc_type, 'doc_number' => $p->doc_number, 'pax_type' => $p->pax_type,
            'nationality' => $p->nationality, 'birth_date' => $p->birth_date?->toDateString(), 'age' => $p->age, 'phone' => $p->phone, 'email' => $p->email,
            'relationship' => $p->relationship, 'is_lead' => $p->is_lead, 'diet_notes' => $p->diet_notes, 'is_placeholder' => $p->is_placeholder,
        ];
    }

    public function service(BookedService $s, array $v): array
    {
        return [
            'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'product' => $s->product ? ['id' => $s->product->id, 'ref' => $s->product->ref, 'name' => $s->product->name] : null,
            'provider' => $s->provider ? ['id' => $s->provider->id, 'name' => $s->provider->name, 'type' => $s->provider->type, 'usable' => $s->provider->isUsable()] : null,
            'service_date' => $s->service_date->toDateString(), 'service_time' => $s->service_time ? substr((string) $s->service_time, 0, 5) : null, 'pax' => $s->pax,
            'status' => $s->status, 'status_label' => BookedService::STATUS_LABELS[$s->status], 'is_required' => $s->is_required,
            'confirmation_code' => $s->confirmation_code, 'confirmed_by' => $s->confirmed_by_name, 'requested_at' => $s->requested_at, 'confirmed_at' => $s->confirmed_at,
            'reject_reason' => $s->reject_reason, 'alt_of' => $s->alt_of_id, 'replaced_by' => $s->replaced_by_id, 'has_departure' => $s->departure_id !== null,
            ...($v['see_cost'] && ! $v['acc'] ? ['cost' => (string) $s->cost_amount] : []),
            ...($v['see_price'] && ! $v['ops_view'] ? ['price' => (string) $s->price_amount] : []),
        ];
    }

    public function receivable(Receivable $c): array
    {
        $overdue = in_array($c->status, ['abierta', 'parcial'], true) && $c->due_date->lt($this->today());

        return [
            'id' => $c->id, 'code' => $c->code, 'concept' => $c->concept, 'amount' => (string) $c->amount, 'paid' => (string) $c->paid_amount,
            'balance' => $c->balance(), 'currency' => $c->currency, 'due_date' => $c->due_date->toDateString(),
            'status' => $overdue ? 'vencida' : $c->status, 'open' => in_array($c->status, Receivable::OPEN, true),
        ];
    }

    private function balance(Reservation $r): string
    {
        if ($r->status === Reservation::CANCELLED) {
            return (string) BigDecimal::of((string) Receivable::where('reservation_id', $r->id)->whereIn('status', Receivable::OPEN)->get()
                ->reduce(fn (BigDecimal $s, Receivable $c) => $s->plus($c->balance()), BigDecimal::zero()))->toScale(2);
        }

        return $this->receivables->balance($r);
    }

    /** @return list<array{tone: string, title: string, body: string}> */
    private function alerts(Reservation $r, ?BookingChange $change, ?CancelRequest $cancel): array
    {
        $out = [];
        if ($cancel) {
            $out[] = ['tone' => 'warn', 'key' => 'cancel_request', 'title' => 'Anulación solicitada', 'body' => "{$this->users->user($cancel->requested_by)['name']} · {$cancel->reason}"];
        }
        if ($r->status === Reservation::CANCELLED) {
            $out[] = ['tone' => 'danger', 'key' => 'cancelled', 'title' => 'Reserva cancelada', 'body' => (string) $r->cancel_reason];
        }
        if ($r->status === Reservation::PENDING_AVAILABILITY) {
            $out[] = ['tone' => 'warn', 'key' => 'availability', 'title' => 'Pendiente de disponibilidad', 'body' => 'No había cupo al registrarla: verifique y confirme cuando exista disponibilidad.'];
        }
        if ($r->status === Reservation::RESCHEDULED) {
            $out[] = ['tone' => 'info', 'key' => 'rescheduled', 'title' => 'Reserva reprogramada', 'body' => 'Los servicios volvieron a pendiente: solicítelos de nuevo a los proveedores y confirme la reserva.'];
        }
        if ($change) {
            $out[] = ['tone' => 'warn', 'key' => 'change', 'title' => 'Modificación pendiente de aprobación', 'body' => BookingChange::TYPES[$change->type].": {$change->description}"];
        }
        $unconfirmed = $r->services->filter(fn (BookedService $s) => in_array($s->status, [BookedService::PENDING, BookedService::REQUESTED], true))->count();
        if ($unconfirmed && ! $r->isClosed()) {
            $out[] = ['tone' => 'info', 'key' => 'services', 'title' => "{$unconfirmed} servicio(s) sin confirmar por el proveedor", 'body' => 'Solicite y registre la confirmación en la pestaña Servicios.'];
        }

        return $out;
    }

    /** @return list<array{key: string, label: string, enabled: bool, reason: ?string, group: string}> */
    public function actions(Reservation $r, User $user, ?CancelRequest $pendingCancel = null, ?BookingChange $pendingChange = null): array
    {
        $out = [];
        $add = function (string $key, string $label, ?string $permission, ?string $blocked = null, string $group = 'primary') use (&$out, $user) {
            $missing = $permission && ! $user->hasPermissionTo($permission);
            $out[] = ['key' => $key, 'label' => $label, 'enabled' => ! $missing && $blocked === null,
                'reason' => $missing ? "Requiere permiso {$permission}" : $blocked, 'group' => $group];
        };
        $closed = $r->isClosed();
        if (! $closed) {
            $add('confirm', 'Confirmar reserva', BookingAccess::MODIFY, in_array($r->status, Reservation::CONFIRMABLE, true) ? null : 'La reserva ya está '.mb_strtolower(Reservation::STATUS_LABELS[$r->status]));
            $add('modify', 'Modificar', BookingAccess::MODIFY, $pendingChange ? 'Hay una modificación pendiente de aprobación' : null);
            $add('reschedule', 'Reprogramar', BookingAccess::MODIFY, $r->status === Reservation::IN_OPERATION ? 'El viaje está en operación' : null);
            if ($user->hasPermissionTo(BookingAccess::CANCEL)) {
                $add('cancel', $pendingCancel ? 'Procesar cancelación' : 'Cancelar', BookingAccess::CANCEL);
            } elseif ($user->hasPermissionTo(BookingAccess::CREATE)) {
                $add('request_cancel', $pendingCancel ? 'Anulación solicitada' : 'Solicitar anulación', BookingAccess::CREATE, match (true) {
                    $pendingCancel !== null => 'Solicitada el '.$pendingCancel->requested_at->format('d/m/Y'),
                    $r->owner_id !== $user->id => 'Solo el asesor dueño puede solicitarla',
                    default => null,
                });
            }
        }
        $add('confirmation_doc', 'Documento de confirmación', BookingAccess::VIEW, null, 'more');
        if (! $closed) {
            $add('request_pending', 'Solicitar todos los servicios pendientes', BookingAccess::MODIFY,
                $r->services->contains('status', BookedService::PENDING) ? null : 'No hay servicios pendientes', 'more');
        }
        $add('register_payment', 'Registrar pago', BookingAccess::PAYMENTS,
            $r->receivables->contains(fn (Receivable $c) => in_array($c->status, Receivable::OPEN, true)) ? null : 'No hay CxC abiertas', 'more');
        if ($r->receivables->isEmpty()) {
            $add('generate_receivables', 'Generar anticipo y saldo', BookingAccess::RECEIVABLES, $r->status === Reservation::CANCELLED ? 'Reserva cancelada' : null, 'more');
        }
        if (in_array($r->status, Reservation::STARTABLE, true)) {
            $add('start_operation', 'Iniciar operación', BookingAccess::MODIFY,
                $r->travel_from->toDateString() > $this->today()->addDay()->toDateString() ? 'Disponible desde el '.$r->travel_from->copy()->subDay()->format('d/m/Y').' (RES-BR-08)' : null, 'more');
        }
        if ($r->status === Reservation::IN_OPERATION) {
            $add('complete', 'Completar viaje', BookingAccess::MODIFY, null, 'more');
        }
        // Spec 05: enlace a la orden operativa existente o creación (PROC-031: solo reservas confirmadas o en operación).
        $orderCode = Order::where('reservation_id', $r->id)->value('code');
        if ($orderCode) {
            $add('operation_order', "Ver orden {$orderCode}", 'operations.view', null, 'more');
        } else {
            $add('operation_order', 'Generar orden operativa', 'operations.assign',
                in_array($r->status, OrderService::ORDERABLE, true) ? null : 'Solo una reserva confirmada genera orden operativa (PROC-031)', 'more');
        }
        $out[array_key_last($out)]['target'] = $orderCode ? "/operaciones/ordenes/{$orderCode}" : "/operaciones/ordenes/nueva?reserva={$r->code}";
        $add('reassign', 'Reasignar asesor', BookingAccess::REASSIGN, $closed ? 'Reserva cerrada' : null, 'more');

        return $out;
    }
}
