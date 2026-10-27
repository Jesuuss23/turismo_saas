<?php

namespace App\Services\Ops;

use App\Events\Ops\OperationClosed;
use App\Exceptions\ApiException;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\Reservation;
use App\Models\Iam\User;
use App\Models\Ops\Assignment;
use App\Models\Ops\Incident;
use App\Models\Ops\Order;
use App\Models\Ops\OrderTask;
use App\Models\Ops\Voucher;
use App\Services\AuditLogger;
use App\Services\Notify;
use App\Services\SequenceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Orden operativa (PROC-031) y cierre operativo (PROC-039): máquina de estados §4.1 y reglas OPS-BR-01..07, 80..84. */
class OrderService
{
    /** OPS-BR-01: solo reservas confirmadas o en operación generan orden. */
    public const ORDERABLE = [Reservation::CONFIRMED, Reservation::IN_OPERATION];

    public function __construct(
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /** OPS-BR-02: reservas sin orden, no canceladas ni completadas, con su validación. */
    public function candidates(): Collection
    {
        $withOrder = Order::query()->pluck('reservation_id');

        return Reservation::whereNotIn('id', $withOrder)->whereNotIn('status', [Reservation::CANCELLED, Reservation::COMPLETED])
            ->with('client:id,name')->withCount('passengers')->orderBy('travel_from')->limit(200)->get()
            ->map(fn (Reservation $r) => [
                'id' => $r->id, 'code' => $r->code, 'title' => $r->title, 'client' => $r->client?->name, 'status' => $r->status,
                'status_label' => Reservation::STATUS_LABELS[$r->status] ?? $r->status, 'travel_from' => $r->travel_from->toDateString(),
                'pax' => $r->passengers_count, 'ok' => in_array($r->status, self::ORDERABLE, true),
                'reason' => in_array($r->status, self::ORDERABLE, true) ? null : 'Solo una reserva confirmada genera orden operativa (PROC-031)',
            ]);
    }

    public function create(Reservation $reservation, ?string $coordinatorId, User $actor): Order
    {
        if (! in_array($reservation->status, self::ORDERABLE, true)) {
            throw ApiException::unprocessable('PROC-031', 'Solo una reserva confirmada genera orden operativa (está '.mb_strtolower(Reservation::STATUS_LABELS[$reservation->status]).').');
        }
        $coordinator = $this->coordinator($coordinatorId, $actor);

        return DB::transaction(function () use ($reservation, $coordinator, $actor) {
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if (Order::where('reservation_id', $reservation->id)->exists()) {
                throw ApiException::rule('OPS-BR-02', "La reserva {$reservation->code} ya tiene orden operativa");
            }
            // OPS-BR-03: servicios activos con proveedor que requieren recursos.
            $services = $reservation->services()->whereNotIn('status', [BookedService::CANCELLED, BookedService::REJECTED])
                ->whereNotNull('provider_id')->where('requires_assignment', true)->get();
            try {
                $order = Order::create([
                    'code' => $this->sequences->next('ops_order'), 'reservation_id' => $reservation->id, 'title' => $reservation->title,
                    'status' => $services->isEmpty() ? Order::PLANNED : Order::PENDING_ASSIGNMENT, 'coordinator_id' => $coordinator->id,
                    'date_from' => $reservation->travel_from->toDateString(), 'date_to' => $reservation->travel_to->toDateString(),
                ]);
            } catch (QueryException) {
                throw ApiException::rule('OPS-BR-02', "La reserva {$reservation->code} ya tiene orden operativa");
            }
            foreach ($services as $service) {
                $this->newAssignment($order, $service);
            }
            foreach (Order::DEFAULT_TASKS as $i => $title) {
                OrderTask::create(['order_id' => $order->id, 'title' => $title, 'position' => $i + 1]);
            }
            $this->audit->log('Orden operativa', $order->code, 'crear', null, "Reserva {$reservation->code} · {$services->count()} servicio(s) con recursos", actor: $actor);
            $this->notify->push('orden.creada', 'email', $coordinator, "Orden {$order->code} asignada", "{$reservation->code} · {$reservation->title}",
                ['client_id' => $reservation->client_id, 'related_type' => 'ops_order', 'related_id' => $order->id]);

            return $order->fresh();
        });
    }

    public function newAssignment(Order $order, BookedService $service): Assignment
    {
        return Assignment::create([
            'order_id' => $order->id, 'reservation_service_id' => $service->id, 'service_date' => $service->service_date->toDateString(),
            'service_time' => $service->service_time, 'service_label' => mb_substr($service->name, 0, 200), 'status' => Assignment::PENDING,
        ]);
    }

    /** `ensureAssignment`: asignación para un servicio de la orden que no la tenía. */
    public function ensureAssignment(Order $order, BookedService $service, User $actor): Assignment
    {
        $this->assertActive($order);
        if ($service->reservation_id !== $order->reservation_id || ! $service->isActive()) {
            throw ApiException::unprocessable('OPS-BR-20', 'El servicio no pertenece a la orden o está cancelado.');
        }
        $existing = Assignment::where('order_id', $order->id)->where('reservation_service_id', $service->id)->first();
        if ($existing) {
            return $existing;
        }
        $assignment = $this->newAssignment($order, $service);
        $this->audit->log('Orden operativa', $order->code, 'agregar_asignacion', null, "{$service->code} · {$service->name}", actor: $actor);
        $this->recompute($order->fresh());

        return $assignment;
    }

    /** OPS-BR-04: usuario activo con rol de Operaciones, Gerencia o Admin; por defecto el actual si es de Operaciones. */
    public function coordinator(?string $id, User $actor): User
    {
        $user = $id ? User::find($id) : ($actor->hasRole('operations') ? $actor : null);
        if (! $user) {
            throw ApiException::invalid('coordinator_id', 'Seleccione el coordinador de la orden (OPS-BR-04).');
        }
        if (! $user->isActive() || ! $user->hasAnyRole(OpsAccess::COORDINATOR_ROLES)) {
            throw ApiException::invalid('coordinator_id', 'El coordinador debe ser un usuario activo de Operaciones, Gerencia o Administración (OPS-BR-04).');
        }

        return $user;
    }

    public function setCoordinator(Order $order, string $userId, User $actor): Order
    {
        $this->assertActive($order);
        $user = $this->coordinator($userId, $actor);
        $before = $order->coordinator?->name;
        $order->forceFill(['coordinator_id' => $user->id])->save();
        $this->audit->log('Orden operativa', $order->code, 'cambio_responsable', $before, $user->name, actor: $actor);
        $this->notify->push('orden.responsable', 'email', $user, "Ahora coordina la orden {$order->code}", $order->title,
            ['related_type' => 'ops_order', 'related_id' => $order->id]);

        return $order->fresh();
    }

    // ── Transiciones manuales (§4.1, OPS-BR-05) ─────────────────────────

    public function toAssignment(Order $order, User $actor): Order
    {
        $this->expect($order, [Order::PLANNED], 'Solo una orden planificada pasa a asignación');

        return $this->move($order, Order::PENDING_ASSIGNMENT, $actor);
    }

    public function markAssigned(Order $order, User $actor): Order
    {
        $this->expect($order, [Order::PENDING_ASSIGNMENT], 'Solo una orden pendiente de asignación se marca como asignada');
        $pending = $order->assignments()->where('status', Assignment::PENDING)->count();
        if ($pending > 0) {
            throw ApiException::rule('OPS-BR-05', "Hay {$pending} servicio(s) sin recursos: asígnelos antes de marcar la orden como asignada", ['pending' => $pending]);
        }

        return $this->move($order, Order::ASSIGNED, $actor);
    }

    public function confirm(Order $order, User $actor): Order
    {
        $this->expect($order, [Order::ASSIGNED], 'Solo una orden asignada se confirma');
        $issues = $this->confirmIssues($order);
        if ($issues) {
            throw ApiException::rule('OPS-BR-05', 'No se puede confirmar la operación: '.implode(' · ', $issues), ['issues' => $issues]);
        }

        return $this->move($order, Order::CONFIRMED, $actor);
    }

    /** @return list<string> */
    public function confirmIssues(Order $order): array
    {
        $unconfirmed = $order->assignments()->whereNotIn('status', Assignment::CONFIRMED_OR_LATER)->count();
        $supplier = BookedService::where('reservation_id', $order->reservation_id)->whereIn('status', [BookedService::PENDING, BookedService::REQUESTED])->count();

        return array_values(array_filter([
            $unconfirmed ? "{$unconfirmed} asignación(es) sin confirmar por el recurso" : null,
            $supplier ? "{$supplier} servicio(s) pendientes o solicitados al proveedor" : null,
        ]));
    }

    /** OPS-BR-06: iniciar ejecución (web o móvil `start`) pasa la reserva a `en_operacion`. */
    public function start(Order $order, User $actor, string $via = 'web'): Order
    {
        $this->expect($order, [Order::CONFIRMED], 'Solo una orden confirmada inicia ejecución');

        return $this->moveToExecution($order, $actor, $via);
    }

    public function moveToExecution(Order $order, User $actor, string $via): Order
    {
        $order = $this->move($order, Order::IN_EXECUTION, $actor, $via === 'app' ? ' · inicio desde app' : '');
        $reservation = $order->reservation;
        if ($reservation && in_array($reservation->status, Reservation::STARTABLE, true)) {
            $before = $reservation->status;
            $reservation->forceFill(['status' => Reservation::IN_OPERATION])->save();
            $this->audit->log('Reserva', $reservation->code, 'cambio_estado', Reservation::STATUS_LABELS[$before], Reservation::STATUS_LABELS[Reservation::IN_OPERATION]." · orden {$order->code}", actor: $actor);
        }

        return $order;
    }

    /** `recomputeOp`: tras asignar, confirmar recurso o liberar (§4.1). */
    public function recompute(Order $order): Order
    {
        if (! in_array($order->status, [Order::PLANNED, ...Order::RECOMPUTABLE], true)) {
            return $order;
        }
        $statuses = $order->assignments()->pluck('status');
        if ($statuses->isEmpty()) {
            return $order;
        }
        $to = match (true) {
            $statuses->contains(Assignment::PENDING) => Order::PENDING_ASSIGNMENT,
            $statuses->every(fn ($s) => in_array($s, Assignment::CONFIRMED_OR_LATER, true)) => Order::CONFIRMED,
            default => Order::ASSIGNED,
        };
        if ($to !== $order->status) {
            $order->forceFill(['status' => $to])->save();
        }

        return $order->fresh();
    }

    // ── Cierre operativo (PROC-039) ─────────────────────────────────────

    /** 4 precondiciones (OPS-BR-80..83) + tareas pendientes (no bloquean, OPS-BR-07). */
    public function closeChecks(Order $order): array
    {
        $services = BookedService::where('reservation_id', $order->reservation_id)->where('status', '<>', BookedService::CANCELLED)
            ->where('status', '<>', BookedService::REJECTED)->whereNotNull('provider_id')->get();
        $notDone = $services->where('status', '<>', BookedService::COMPLETED);
        $openIncidents = $order->incidents()->whereIn('status', Incident::ACTIVE)->pluck('code');
        $evidences = $order->evidences()->count();
        $highWithout = $order->incidents()->whereIn('severity', ['alta', 'critica'])->whereDoesntHave('evidences')->pluck('code');
        $noCost = $services->filter(fn (BookedService $s) => (float) $s->cost_amount <= 0);

        $checks = [
            ['key' => 'services', 'rule' => 'OPS-BR-80', 'label' => 'Servicios completados', 'ok' => $notDone->isEmpty(),
                'detail' => $notDone->isEmpty() ? "{$services->count()} de {$services->count()} completados" : $notDone->count().' sin completar: '.$notDone->pluck('code')->implode(', ')],
            ['key' => 'incidents', 'rule' => 'OPS-BR-81', 'label' => 'Incidencias gestionadas', 'ok' => $openIncidents->isEmpty(),
                'detail' => $openIncidents->isEmpty() ? 'Sin incidencias abiertas o en proceso' : 'Abiertas: '.$openIncidents->implode(', ')],
            ['key' => 'evidences', 'rule' => 'OPS-BR-82', 'label' => 'Evidencias registradas', 'ok' => $evidences > 0 && $highWithout->isEmpty(),
                'detail' => $evidences === 0 ? 'La orden no tiene evidencias' : ($highWithout->isEmpty() ? "{$evidences} evidencia(s)" : 'Incidencias alta/crítica sin evidencia: '.$highWithout->implode(', '))],
            ['key' => 'costs', 'rule' => 'OPS-BR-83', 'label' => 'Costos disponibles', 'ok' => $noCost->isEmpty(),
                'detail' => $noCost->isEmpty() ? 'Todos los servicios con proveedor tienen costo' : 'Sin costo: '.$noCost->pluck('code')->implode(', ')],
        ];

        return [
            'checks' => $checks, 'ready' => collect($checks)->every('ok') && $order->status === Order::IN_EXECUTION,
            'status_ok' => $order->status === Order::IN_EXECUTION,
            'pending_tasks' => $order->tasks()->where('done', false)->pluck('title')->all(),
        ];
    }

    public function close(Order $order, User $actor): Order
    {
        return DB::transaction(function () use ($order, $actor) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            // §12-1: solo desde `en_ejecucion` (el prototipo no lo verificaba).
            $this->expect($order, [Order::IN_EXECUTION], 'Solo se cierra una orden en ejecución');
            $result = $this->closeChecks($order);
            foreach ($result['checks'] as $check) {
                if (! $check['ok']) {
                    throw ApiException::unprocessable($check['rule'], "{$check['label']}: {$check['detail']}", ['checks' => $result['checks']]);
                }
            }
            $order->forceFill(['status' => Order::COMPLETED, 'closed_at' => now(), 'closed_by' => $actor->id])->save();
            $this->audit->log('Orden operativa', $order->code, 'cierre', Order::STATUS_LABELS[Order::IN_EXECUTION], 'Completada · 4 precondiciones verificadas', actor: $actor);
            $reservation = $order->reservation;
            if ($reservation && $reservation->status !== Reservation::COMPLETED) {
                $before = $reservation->status;
                $reservation->forceFill(['status' => Reservation::COMPLETED])->save();
                $this->audit->log('Reserva', $reservation->code, 'cambio_estado', Reservation::STATUS_LABELS[$before] ?? $before, "Completada · cierre de {$order->code}", actor: $actor);
            }
            $data = ['client_id' => $reservation?->client_id, 'related_type' => 'ops_order', 'related_id' => $order->id];
            if ($order->coordinator) {
                $this->notify->push('operacion.cerrada', 'email', $order->coordinator, "Operación {$order->code} cerrada", $order->title, $data);
            }
            foreach (User::where('client_id', $reservation?->client_id)->whereNotNull('client_id')->get() as $client) {
                $this->notify->push('operacion.cerrada', 'portal', $client, "Su viaje {$order->title} ha finalizado", 'Cuéntenos su experiencia', $data);
            }
            OperationClosed::dispatch($order->id);

            return $order->fresh();
        });
    }

    /** §12-10: cancelación de la reserva con orden activa → orden cancelada, recursos liberados, vouchers anulados. */
    public function cancelForReservation(Reservation $reservation, ?User $actor): void
    {
        $order = Order::where('reservation_id', $reservation->id)->first();
        if (! $order || ! $order->isActive()) {
            return;
        }
        foreach ($order->assignments()->where('status', '<>', Assignment::COMPLETED)->get() as $assignment) {
            $assignment->forceFill(['guide_id' => null, 'driver_id' => null, 'vehicle_id' => null, 'status' => Assignment::PENDING, 'confirmed_at' => null])->save();
        }
        $order->forceFill(['status' => Order::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => "Reserva {$reservation->code} cancelada"])->save();
        $this->audit->log('Orden operativa', $order->code, 'cambio_estado', null, 'Cancelada · reserva cancelada', actor: $actor);
        foreach (Voucher::where('reservation_id', $reservation->id)->where('status', Voucher::ISSUED)->with('provider')->get() as $voucher) {
            $voucher->forceFill(['status' => Voucher::VOIDED, 'void_reason' => 'Reserva cancelada', 'void_at' => now(), 'void_by' => $actor?->id])->save();
            $this->audit->log('Voucher', $voucher->code, 'anular', 'Emitido', 'Anulado · reserva cancelada', actor: $actor);
            if ($voucher->provider?->email) {
                $this->notify->push('voucher.anulado', 'email', $voucher->provider->email, "Voucher {$voucher->code} anulado", 'Reserva cancelada',
                    ['related_type' => 'ops_voucher', 'related_id' => $voucher->id]);
            }
        }
    }

    // ── Tareas (§3.2) ───────────────────────────────────────────────────

    public function addTask(Order $order, string $title, User $actor): OrderTask
    {
        $this->assertActive($order);

        return OrderTask::create(['order_id' => $order->id, 'title' => trim($title), 'position' => (int) $order->tasks()->max('position') + 1]);
    }

    public function toggleTask(OrderTask $task, bool $done, User $actor): OrderTask
    {
        $task->forceFill(['done' => $done, 'done_by' => $done ? $actor->id : null, 'done_at' => $done ? now() : null])->save();

        return $task->fresh();
    }

    public function deleteTask(OrderTask $task, User $actor): void
    {
        $order = Order::find($task->order_id);
        $task->delete();
        $this->audit->log('Orden operativa', $order?->code ?? $task->order_id, 'eliminar_tarea', $task->title, null, actor: $actor);
    }

    /** Costos de proveedor; venta y margen solo con permiso de rentabilidad (§7, §11-18). */
    public function costSummary(Order $order, bool $withMargin): array
    {
        $reservation = $order->reservation;
        $services = BookedService::where('reservation_id', $order->reservation_id)->whereNotIn('status', [BookedService::CANCELLED, BookedService::REJECTED])
            ->with('provider:id,name')->orderBy('service_date')->get();
        $cost = round((float) $services->sum(fn ($s) => (float) $s->cost_amount), 2);
        $summary = [
            'currency' => $reservation?->currency, 'cost' => number_format($cost, 2, '.', ''),
            'lines' => $services->map(fn (BookedService $s) => [
                'code' => $s->code, 'name' => $s->name, 'provider' => $s->provider?->name, 'date' => $s->service_date->toDateString(),
                'status' => $s->status, 'cost' => (string) $s->cost_amount,
            ])->values()->all(),
            'can_see_margin' => $withMargin,
        ];
        if ($withMargin) {
            $sale = (float) $reservation?->total_amount;
            $summary += ['sale' => number_format($sale, 2, '.', ''), 'margin' => number_format($sale - $cost, 2, '.', ''),
                'margin_pct' => $sale > 0 ? round(($sale - $cost) / $sale * 100, 1) : null];
        }

        return $summary;
    }

    public function assertActive(Order $order): void
    {
        if (! $order->isActive()) {
            throw ApiException::rule('OPS-BR-05', "La orden {$order->code} está ".mb_strtolower(Order::STATUS_LABELS[$order->status]).': no admite cambios');
        }
    }

    private function expect(Order $order, array $from, string $message): void
    {
        if (! in_array($order->status, $from, true)) {
            throw ApiException::rule('OPS-BR-05', "{$message} (está ".mb_strtolower(Order::STATUS_LABELS[$order->status]).').');
        }
    }

    private function move(Order $order, string $to, User $actor, string $suffix = ''): Order
    {
        $before = $order->status;
        $order->forceFill(['status' => $to])->save();
        $this->audit->log('Orden operativa', $order->code, 'cambio_estado', Order::STATUS_LABELS[$before], Order::STATUS_LABELS[$to].$suffix, actor: $actor);

        return $order->fresh();
    }
}
