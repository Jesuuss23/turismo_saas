<?php

namespace App\Services\Bookings;

use App\Events\Bookings\ReservationCancelled;
use App\Exceptions\ApiException;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\Cancellation;
use App\Models\Bookings\CancelRequest;
use App\Models\Bookings\Reservation;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Catalog\AvailabilityService;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/** Cancelación con penalidad por política snapshot (PROC-028, §4.6) y solicitud de anulación Ventas → Gerencia (RES-BR-33). */
class CancellationService
{
    public const REASONS = ['Solicitud del cliente', 'Fuerza mayor', 'Salud', 'Cambio de planes', 'No pago', 'Error de registro', 'Otro'];

    public function __construct(
        private readonly ReservationService $reservations,
        private readonly AvailabilityService $availability,
        private readonly ReceivableService $receivables,
        private readonly CrmClock $clock,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /** Líneas, penalidad, reembolso y efectos (sin cambios). */
    public function preview(Reservation $reservation): array
    {
        $this->reservations->assertOpen($reservation);
        $services = $reservation->services()->with('product')->get();
        $today = $this->clock->today();
        $active = $services->filter(fn (BookedService $s) => $s->isActive())->map(fn (BookedService $s) => [
            'code' => $s->code, 'name' => $s->name, 'product' => $s->product?->ref, 'price' => (string) $s->price_amount,
            'days' => (int) $today->diffInDays($s->service_date, false), 'policy' => $this->policyText($reservation, $s),
        ])->values()->all();
        $consumed = $services->where('status', BookedService::COMPLETED)->map(fn (BookedService $s) => (string) $s->price_amount)->values()->all();
        $paid = $this->receivables->paid($reservation);
        $result = Penalty::compute((string) $reservation->total_amount, $paid, $active, $consumed);
        $departures = $services->filter(fn (BookedService $s) => $s->isActive() && $s->departure_id)->pluck('departure_id')->unique()->count();
        $openReceivables = $reservation->receivables()->whereNotIn('status', ['pagada', 'cancelada'])->count();

        return $result + [
            'currency' => $reservation->currency, 'total' => (string) $reservation->total_amount, 'paid' => $paid,
            'effects' => array_values(array_filter([
                $departures ? "Libera los cupos de {$departures} salida(s)" : null,
                count($active).' servicio(s) pasan a cancelado',
                $openReceivables ? "{$openReceivables} CxC abierta(s) se cancelan" : null,
                $result['owed'] !== '0.00' ? "CxC de penalidad por {$reservation->currency} {$result['owed']} a 7 días" : null,
                $result['refund'] !== '0.00' ? "Nota de crédito y reembolso pendiente por {$reservation->currency} {$result['refund']}" : null,
            ])),
            'pending_request' => $this->pendingRequest($reservation)?->only(['id', 'reason', 'requested_at']),
        ];
    }

    /** §4.6 efectos 1–9 en una transacción con los cupos bloqueados. */
    public function cancel(Reservation $reservation, string $reason, string $detail, User $actor): Reservation
    {
        if (trim($reason) === '' || trim($detail) === '') {
            throw ApiException::invalid(trim($reason) === '' ? 'reason' : 'detail', 'Indique el motivo y el detalle de la cancelación (RES-BR-30).');
        }
        $this->reservations->assertOpen($reservation);
        $ids = $reservation->services()->whereNotIn('status', BookedService::INACTIVE)->whereNotNull('departure_id')->pluck('departure_id')->unique()->values()->all();

        return $this->availability->lockMany($ids, function (Collection $departures) use ($reservation, $reason, $detail, $actor) {
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $this->reservations->assertOpen($reservation);
            $calc = $this->preview($reservation);
            $motive = trim($reason).': '.trim($detail);

            foreach ($departures as $d) {
                $this->availability->releaseLocked($d, $reservation->id, "Cancelación {$reservation->code}", $actor);
            }
            $reservation->services()->whereNotIn('status', BookedService::INACTIVE)->update(['status' => BookedService::CANCELLED, 'updated_at' => now(), 'updated_by' => $actor->id]);
            $this->receivables->cancelOpen($reservation, $actor);
            $penaltyReceivable = null;
            if ($calc['owed'] !== '0.00') {
                $penaltyReceivable = $this->receivables->create($reservation, "Penalidad por cancelación — {$reservation->code}", $calc['owed'],
                    $this->clock->today()->addDays(7)->toDateString(), $actor, 'cancelación');
            }
            [$note, $refund] = $calc['refund'] !== '0.00' ? $this->receivables->refund($reservation, $calc['refund'], $calc['penalty'], $actor) : [null, null];

            $before = Reservation::STATUS_LABELS[$reservation->status];
            $reservation->forceFill([
                'status' => Reservation::CANCELLED, 'cancel_reason' => $motive, 'cancelled_at' => now(),
                'penalty_amount' => $calc['penalty'], 'refund_amount' => $calc['refund'],
            ])->save();
            Cancellation::create([
                'reservation_id' => $reservation->id, 'total_at_cancel' => $calc['total'], 'paid_at_cancel' => $calc['paid'],
                'penalty_services' => $calc['penalty_services'], 'consumed_amount' => $calc['consumed'], 'factor_k' => $calc['k'],
                'penalty_amount' => $calc['penalty'], 'refund_amount' => $calc['refund'], 'owed_amount' => $calc['owed'], 'lines' => $calc['lines'],
                'credit_note_id' => $note?->id, 'refund_payment_id' => $refund?->id, 'penalty_receivable_id' => $penaltyReceivable?->id,
                'reason' => $motive, 'cancelled_by' => $actor->id, 'cancelled_at' => now(),
            ]);
            $this->audit->log('Reserva', $reservation->code, 'cancelar', $before,
                "Cancelada · penalidad {$reservation->currency} {$calc['penalty']} · reembolso {$calc['refund']} · por cobrar {$calc['owed']} · {$motive}", actor: $actor);

            $data = ['client_id' => $reservation->client_id, 'related_type' => 'reservation', 'related_id' => $reservation->id];
            if ($request = $this->pendingRequest($reservation)) {
                $request->forceFill(['status' => 'aprobada', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => 'Cancelación procesada'])->save();
                if ($requester = User::find($request->requested_by)) {
                    $this->notify->push('reserva.anulacion_aprobada', 'portal', $requester, "Anulación aprobada · {$reservation->code}", 'La reserva fue cancelada', $data);
                }
            }
            if ($email = $reservation->client?->email) {
                $this->notify->push('reserva.cancelada', 'email', $email, "Su reserva {$reservation->code} fue cancelada",
                    $calc['refund'] !== '0.00' ? "Reembolso de {$reservation->currency} {$calc['refund']} en 7–15 días hábiles" : "Penalidad aplicada: {$reservation->currency} {$calc['penalty']}", $data);
            }
            if ($coordinator = CatalogRecipients::operations()->first()) {
                $this->notify->push('reserva.cancelada', 'push', $coordinator, "Reserva {$reservation->code} cancelada", $reservation->title, $data);
            }
            foreach (CatalogRecipients::role('finance') as $user) {
                $this->notify->push('reserva.cancelada', 'portal', $user, "Reserva {$reservation->code} cancelada",
                    "Penalidad {$reservation->currency} {$calc['penalty']} · reembolso {$calc['refund']} · por cobrar {$calc['owed']}", $data);
            }
            ReservationCancelled::dispatch($reservation->id);

            return $reservation->fresh();
        });
    }

    public function pendingRequest(Reservation $reservation): ?CancelRequest
    {
        return $reservation->cancelRequests()->where('status', 'pendiente')->first();
    }

    /** RES-BR-33: solo el dueño, reserva no cerrada, una pendiente. */
    public function request(Reservation $reservation, string $reason, string $detail, User $actor): CancelRequest
    {
        $this->reservations->assertOpen($reservation);
        if ($reservation->owner_id !== $actor->id) {
            throw ApiException::forbidden('RES-BR-33', 'Solo el asesor dueño de la reserva puede solicitar su anulación', ['reservations.create']);
        }
        if (trim($reason) === '' || trim($detail) === '') {
            throw ApiException::invalid(trim($reason) === '' ? 'reason' : 'detail', 'Indique el motivo y el detalle de la solicitud.');
        }
        if ($this->pendingRequest($reservation)) {
            throw ApiException::rule('RES-BR-33', 'Ya existe una solicitud de anulación pendiente para esta reserva');
        }
        try {
            $request = CancelRequest::create([
                'reservation_id' => $reservation->id, 'reason' => trim($reason).': '.trim($detail), 'requested_by' => $actor->id,
                'requested_at' => now(), 'status' => 'pendiente',
            ]);
        } catch (QueryException) {
            throw ApiException::rule('RES-BR-33', 'Ya existe una solicitud de anulación pendiente para esta reserva');
        }
        $this->audit->log('Reserva', $reservation->code, 'solicitar_anulacion', null, $request->reason, actor: $actor);
        foreach (CatalogRecipients::managers() as $manager) {
            $this->notify->push('reserva.anulacion_solicitada', 'portal', $manager, "Anulación solicitada · {$reservation->code}", "{$actor->name}: {$request->reason}",
                ['related_type' => 'reservation', 'related_id' => $reservation->id]);
        }

        return $request;
    }

    public function rejectRequest(Reservation $reservation, CancelRequest $request, string $note, User $actor): CancelRequest
    {
        if ($request->status !== 'pendiente') {
            throw ApiException::rule('RES-BR-33', 'La solicitud ya fue resuelta');
        }
        if (trim($note) === '') {
            throw ApiException::invalid('note', 'Indique el motivo del rechazo.');
        }
        $request->forceFill(['status' => 'rechazada', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => trim($note)])->save();
        $this->audit->log('Reserva', $reservation->code, 'rechazar_solicitud_anulacion', $request->reason, "Rechazada · {$note}", actor: $actor);
        if ($requester = User::find($request->requested_by)) {
            $this->notify->push('reserva.anulacion_rechazada', 'portal', $requester, "Anulación rechazada · {$reservation->code}", $note,
                ['related_type' => 'reservation', 'related_id' => $reservation->id]);
        }

        return $request->fresh();
    }

    /** Texto de la política de cancelación del snapshot (o del producto vigente). */
    private function policyText(Reservation $reservation, BookedService $service): ?string
    {
        $snapshot = $reservation->policies_snapshot ?? [];

        return $snapshot[$service->product_id]['cancel'] ?? $service->product?->policies?->cancellation;
    }
}
