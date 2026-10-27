<?php

namespace App\Services\Ops;

use App\Exceptions\ApiException;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\Reservation;
use App\Models\Iam\User;
use App\Models\Ops\Assignment;
use App\Models\Ops\Order;
use App\Services\AuditLogger;
use App\Services\Bookings\SupplierFlow;
use App\Services\Crm\CrmClock;
use App\Services\Notify;

/** Servicio de la reserva frente al proveedor desde Operaciones (PROC-032, §4.3) y completar desde web (OPS-BR-13). */
class ServiceOps
{
    public function __construct(
        private readonly SupplierFlow $supplier,
        private readonly CrmClock $clock,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /** OPS-BR-10: solo servicios `pendiente`. */
    public function request(BookedService $service, User $actor): BookedService
    {
        return $this->supplier->request($this->reservation($service), $service, $actor);
    }

    /** OPS-BR-11: código obligatorio. */
    public function confirm(BookedService $service, array $data, User $actor): BookedService
    {
        return $this->supplier->confirm($this->reservation($service), $service, $data, $actor);
    }

    /** OPS-BR-12: motivo obligatorio y solo desde `solicitado`; email al coordinador ("Busque alternativa"). */
    public function reject(BookedService $service, string $reason, User $actor): BookedService
    {
        if ($service->status !== BookedService::REQUESTED) {
            throw ApiException::rule('OPS-BR-12', "Solo se registra el rechazo de un servicio solicitado ({$service->code} está ".mb_strtolower(BookedService::STATUS_LABELS[$service->status] ?? $service->status).').');
        }
        [$rejected] = $this->supplier->reject($this->reservation($service), $service, ['reason' => $reason, 'detail' => 'Registrado por Operaciones'], $actor);
        $order = Order::where('reservation_id', $service->reservation_id)->first();
        if ($order?->coordinator) {
            $this->notify->push('proveedor.rechazo', 'email', $order->coordinator, "Proveedor rechazó {$service->code} · {$order->code}", "{$service->name}: {$reason}. Busque alternativa.",
                ['related_type' => 'ops_order', 'related_id' => $order->id]);
        }

        return $rejected;
    }

    /** OPS-BR-13: no antes de su fecha; asignación → completada. */
    public function complete(BookedService $service, User $actor): BookedService
    {
        if (! in_array($service->status, [BookedService::CONFIRMED, BookedService::IN_PROGRESS], true)) {
            throw ApiException::rule('PROC-036', "Solo se completa un servicio confirmado o en curso ({$service->code} está ".mb_strtolower(BookedService::STATUS_LABELS[$service->status] ?? $service->status).').');
        }
        if ($service->service_date->toDateString() > $this->clock->today()->toDateString()) {
            throw ApiException::rule('OPS-BR-13', "El servicio no puede completarse antes de su fecha ({$service->service_date->toDateString()}, PROC-036).");
        }
        $before = BookedService::STATUS_LABELS[$service->status];
        $service->forceFill(['status' => BookedService::COMPLETED])->save();
        $this->audit->log('Servicio reserva', $service->code, 'completar', $before, 'Completado · desde web', actor: $actor);
        foreach (Assignment::where('reservation_service_id', $service->id)->where('status', '<>', Assignment::COMPLETED)->get() as $assignment) {
            $assignment->forceFill(['status' => Assignment::COMPLETED, 'finished_at' => $assignment->finished_at ?? now()])->save();
        }

        return $service->fresh();
    }

    private function reservation(BookedService $service): Reservation
    {
        return Reservation::findOrFail($service->reservation_id);
    }
}
