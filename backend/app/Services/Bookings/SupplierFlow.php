<?php

namespace App\Services\Bookings;

use App\Exceptions\ApiException;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Provider;
use App\Models\Iam\User;
use App\Models\Ops\Assignment;
use App\Models\Ops\SupplierRequest;
use App\Services\AuditLogger;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use App\Services\SequenceService;

/**
 * Servicios de la reserva frente al proveedor (§4.2, PROC-032 parcial): solicitar (RES-BR-11), confirmar con
 * código (RES-BR-12) y rechazo con alternativa opcional (RES-BR-13).
 */
class SupplierFlow
{
    public const REJECT_REASONS = ['Sin disponibilidad', 'Tarifa no vigente', 'Fecha bloqueada', 'Capacidad insuficiente', 'Otro'];

    public function __construct(
        private readonly ReservationService $reservations,
        private readonly CrmClock $clock,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    public function request(Reservation $reservation, BookedService $service, User $actor): BookedService
    {
        $this->reservations->assertOpen($reservation);
        if ($service->status !== BookedService::PENDING) {
            throw ApiException::rule('RES-BR-11', "El servicio {$service->code} está ".mb_strtolower(BookedService::STATUS_LABELS[$service->status]).': solo se solicitan servicios pendientes');
        }
        $provider = $service->provider;
        if (! $provider?->isUsable()) {
            throw ApiException::rule('RES-BR-11', 'RES-BR-11 / PRD-BR-007: solo se solicita a proveedores activos'.($provider ? " ({$provider->name} está {$provider->effectiveStatus()})" : ' (el servicio no tiene proveedor)'));
        }
        $this->send($reservation, $service, $provider, $actor);

        return $service->fresh();
    }

    /** "Solicitar todos los servicios pendientes": omite los de proveedor no activo. @return array{requested: list<string>, skipped: list<array>} */
    public function requestPending(Reservation $reservation, User $actor): array
    {
        $this->reservations->assertOpen($reservation);
        $requested = [];
        $skipped = [];
        foreach ($reservation->services()->with('provider')->where('status', BookedService::PENDING)->get() as $service) {
            if (! $service->provider?->isUsable()) {
                $skipped[] = ['code' => $service->code, 'reason' => $service->provider ? "Proveedor {$service->provider->name} {$service->provider->effectiveStatus()}" : 'Sin proveedor'];

                continue;
            }
            $this->send($reservation, $service, $service->provider, $actor);
            $requested[] = $service->code;
        }

        return ['requested' => $requested, 'skipped' => $skipped];
    }

    private function send(Reservation $reservation, BookedService $service, Provider $provider, User $actor): void
    {
        $service->forceFill(['status' => BookedService::REQUESTED, 'requested_at' => now(), 'requested_by' => $actor->id])->save();
        // Historial de solicitudes (spec 05 §3.4): base de la extranet de proveedores.
        SupplierRequest::create([
            'reservation_service_id' => $service->id, 'provider_id' => $provider->id, 'status' => 'solicitado', 'channel' => 'email',
            'requested_at' => now(), 'requested_by' => $actor->id,
        ]);
        $this->audit->log('Servicio reserva', $service->code, 'solicitar_proveedor', 'Pendiente', "Solicitado a {$provider->name} · {$reservation->code}", actor: $actor);
        if ($provider->email) {
            $this->notify->push('proveedor.solicitud', 'email', $provider->email, "Solicitud de servicio {$service->code} · {$reservation->code}",
                "{$service->name} · {$service->service_date->toDateString()}".($service->service_time ? ' '.substr((string) $service->service_time, 0, 5) : '')." · {$service->pax} pax",
                ['related_type' => 'reservation', 'related_id' => $reservation->id]);
        }
    }

    /** RES-BR-12: código obligatorio; si era el último pendiente, la reserva `pendiente_proveedor` vuelve a `confirmada`. */
    public function confirm(Reservation $reservation, BookedService $service, array $data, User $actor): BookedService
    {
        $this->reservations->assertOpen($reservation);
        // Spec 05 §4.3: también un rechazado sin alternativa puede confirmarse (el proveedor reconsidera).
        $rejectedAlone = $service->status === BookedService::REJECTED && ! $service->replaced_by_id;
        if (! in_array($service->status, [BookedService::PENDING, BookedService::REQUESTED], true) && ! $rejectedAlone) {
            throw ApiException::rule('RES-BR-12', "El servicio {$service->code} no está pendiente ni solicitado");
        }
        $code = trim((string) ($data['confirmation_code'] ?? ''));
        if ($code === '') {
            throw ApiException::invalid('confirmation_code', 'Indique el código de confirmación del proveedor (RES-BR-12).');
        }
        $service->forceFill([
            'status' => BookedService::CONFIRMED, 'confirmation_code' => $code, 'confirmed_at' => now(), 'confirmed_by' => $actor->id,
            'confirmed_by_name' => $data['confirmed_by'] ?? null, 'service_time' => $data['time'] ?? $service->service_time,
        ])->save();
        $this->audit->log('Servicio reserva', $service->code, 'confirmar_proveedor', BookedService::STATUS_LABELS[$service->getOriginal('status')] ?? 'Solicitado',
            "Confirmado · {$code}".(! empty($data['confirmed_by']) ? " · {$data['confirmed_by']}" : ''), actor: $actor);
        $this->respond($service, ['status' => 'confirmado', 'confirmation_code' => $code, 'note' => $data['note'] ?? null], $actor);
        // La hora confirmada por el proveedor actualiza la copia de la asignación (§3.5).
        Assignment::where('reservation_service_id', $service->id)->update(['service_time' => $service->service_time, 'service_date' => $service->service_date->toDateString()]);

        $reservation = $reservation->fresh();
        $pending = $reservation->services()->get()->contains(fn (BookedService $s) => $s->isActive() && $s->status !== BookedService::CONFIRMED);
        if ($reservation->status === Reservation::PENDING_PROVIDER && ! $pending) {
            $reservation->forceFill(['status' => Reservation::CONFIRMED])->save();
            $this->audit->log('Reserva', $reservation->code, 'cambio_estado', Reservation::STATUS_LABELS[Reservation::PENDING_PROVIDER], 'Confirmada · proveedores confirmados', actor: $actor);
        }

        return $service->fresh();
    }

    /**
     * RES-BR-13: motivo + detalle; alternativa opcional (otro proveedor activo del mismo tipo, o el mismo en otra fecha)
     * como nuevo servicio `pendiente`. Una reserva `confirmada` pasa a `pendiente_proveedor`.
     *
     * @return array{0: BookedService, 1: ?BookedService} rechazado y alternativa
     */
    public function reject(Reservation $reservation, BookedService $service, array $data, User $actor): array
    {
        $this->reservations->assertOpen($reservation);
        if (! in_array($service->status, [BookedService::REQUESTED, BookedService::CONFIRMED], true)) {
            throw ApiException::rule('RES-BR-13', "Solo se registra el rechazo de un servicio solicitado o confirmado ({$service->code} está ".mb_strtolower(BookedService::STATUS_LABELS[$service->status]).')');
        }
        $reason = trim((string) ($data['reason'] ?? ''));
        $detail = trim((string) ($data['detail'] ?? ''));
        if ($reason === '' || $detail === '') {
            throw ApiException::invalid($reason === '' ? 'reason' : 'detail', 'Indique el motivo y el detalle del rechazo (RES-BR-13).');
        }

        $alternative = null;
        $altProvider = $data['alternative_provider_id'] ?? null;
        if ($altProvider !== null && $altProvider !== '') {
            $provider = $altProvider === 'same' ? $service->provider : Provider::find($altProvider);
            if (! $provider?->isUsable()) {
                throw ApiException::invalid('alternative_provider_id', 'La alternativa debe ser un proveedor activo (PRD-BR-007).');
            }
            if ($altProvider !== 'same' && $service->provider && $provider->type !== $service->provider->type) {
                throw ApiException::invalid('alternative_provider_id', "La alternativa debe ser un proveedor del mismo tipo ({$service->provider->type}).");
            }
            $date = ($data['alternative_date'] ?? null) ?: $service->service_date->toDateString();
            if ($altProvider === 'same' && $date === $service->service_date->toDateString()) {
                throw ApiException::invalid('alternative_date', 'Con el mismo proveedor indique otra fecha.');
            }
            if ($date < $this->clock->today()->toDateString()) {
                throw ApiException::invalid('alternative_date', 'La fecha de la alternativa no puede ser pasada.');
            }
            $alternative = BookedService::create([
                'reservation_id' => $reservation->id, 'code' => $this->sequences->next('booking_service'), 'product_id' => $service->product_id,
                'catalog_service_id' => $service->catalog_service_id, 'quote_item_id' => $service->quote_item_id, 'departure_id' => $service->departure_id,
                'provider_id' => $provider->id, 'name' => $service->name, 'service_date' => $date, 'service_time' => $service->service_time,
                'pax' => $service->pax, 'status' => BookedService::PENDING, 'cost_amount' => $service->cost_amount, 'price_amount' => $service->price_amount,
                'is_required' => $service->is_required, 'alt_of_id' => $service->id,
                'service_kind' => $service->service_kind, 'requires_assignment' => $service->requires_assignment,
            ]);
        }
        $before = BookedService::STATUS_LABELS[$service->status];
        $service->forceFill(['status' => BookedService::REJECTED, 'reject_reason' => "{$reason}: {$detail}", 'replaced_by_id' => $alternative?->id])->save();
        $this->respond($service, ['status' => 'rechazado', 'rejection_reason' => mb_substr($reason, 0, 120), 'note' => $detail], $actor);
        $this->audit->log('Servicio reserva', $service->code, 'rechazo_proveedor', $before,
            "Rechazado · {$reason}: {$detail}".($alternative ? " · alternativa {$alternative->code} ({$alternative->provider?->name}, {$alternative->service_date->toDateString()})" : ' · sin alternativa'), actor: $actor);

        $reservation = $reservation->fresh();
        if ($reservation->status === Reservation::CONFIRMED) {
            $reservation->forceFill(['status' => Reservation::PENDING_PROVIDER])->save();
            $this->audit->log('Reserva', $reservation->code, 'cambio_estado', 'Confirmada', Reservation::STATUS_LABELS[Reservation::PENDING_PROVIDER]." · rechazo de {$service->code}", actor: $actor);
        }
        if ($reservation->owner) {
            $this->notify->push('proveedor.rechazo', 'push', $reservation->owner, "Proveedor rechazó {$service->code} · {$reservation->code}", "{$service->name}: {$reason}",
                ['related_type' => 'reservation', 'related_id' => $reservation->id]);
        }

        return [$service->fresh(), $alternative?->fresh()];
    }

    /** Registra la respuesta en la última solicitud abierta del servicio (o crea una registrada por teléfono). */
    private function respond(BookedService $service, array $data, User $actor): void
    {
        $request = SupplierRequest::where('reservation_service_id', $service->id)->where('status', 'solicitado')->latest('requested_at')->first()
            ?? new SupplierRequest(['reservation_service_id' => $service->id, 'provider_id' => $service->provider_id, 'channel' => 'telefono',
                'requested_at' => now(), 'requested_by' => $actor->id]);
        if (! $request->provider_id) {
            return;
        }
        $request->fill($data + ['responded_at' => now(), 'responded_by_user_id' => $actor->id, 'response_source' => 'web_interno'])->save();
    }
}
