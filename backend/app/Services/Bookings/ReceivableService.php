<?php

namespace App\Services\Bookings;

use App\Exceptions\ApiException;
use App\Models\Bookings\Reservation;
use App\Models\Finance\Invoice;
use App\Models\Finance\Payment;
use App\Models\Finance\Receivable;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use App\Services\SequenceService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;

/**
 * Obligaciones de cobro que crea Reservas en las tablas de Finanzas (§3, RES-BR-03/15/32/40/41):
 * CxC 40/60, ajustes, penalidad, pago rápido aplicado, nota de crédito y reembolso pendiente.
 */
class ReceivableService
{
    /** Saldo bajo el cual una reserva `pendiente_pago` vuelve a `confirmada` (§4.1). */
    public const SETTLED_TOLERANCE = '0.50';

    public function __construct(
        private readonly CrmClock $clock,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /** RES-BR-03 (§4.3 paso 14): anticipo 40 % (vence ≤ 3 días) y saldo 60 % (15 días antes del viaje). */
    public function schedule(Reservation $reservation, User $actor, string $origin): void
    {
        if ($reservation->receivables()->where('status', '<>', 'cancelada')->exists()) {
            return;
        }
        $total = BigDecimal::of((string) $reservation->total_amount);
        if (! $total->isPositive()) {
            return;
        }
        $a = $total->multipliedBy('0.40')->toScale(2, RoundingMode::HalfUp);
        $b = $total->minus($a);
        [$dueA, $dueB] = self::dueDates($this->clock->today(), $reservation->travel_from);
        $this->create($reservation, "Anticipo 40% — {$reservation->title}", (string) $a, $dueA, $actor, $origin);
        $this->create($reservation, "Saldo 60% — {$reservation->title}", (string) $b, $dueB, $actor, $origin);
    }

    /** RES-BR-41: anticipo/saldo manual solo si la reserva no tiene CxC y no está cancelada. */
    public function generate(Reservation $reservation, User $actor): void
    {
        if ($reservation->status === Reservation::CANCELLED) {
            throw ApiException::rule('RES-BR-41', 'La reserva está cancelada: no se generan cuentas por cobrar');
        }
        if ($reservation->receivables()->exists()) {
            throw ApiException::rule('RES-BR-41', 'La reserva ya tiene cuentas por cobrar');
        }
        $this->schedule($reservation, $actor, 'generación manual');
    }

    /** @return array{0: string, 1: string} vencimientos del anticipo y del saldo. */
    public static function dueDates(CarbonInterface $today, CarbonInterface $travelFrom): array
    {
        $today = $today->copy()->startOfDay();
        $from = $travelFrom->copy()->startOfDay();
        $dueA = $today->copy()->addDays(3)->min($from->max($today));
        $dueB = $from->copy()->subDays(15)->max($today);

        return [$dueA->toDateString(), $dueB->toDateString()];
    }

    public function create(Reservation $reservation, string $concept, string $amount, string $due, User $actor, string $origin): Receivable
    {
        $receivable = Receivable::create([
            'code' => $this->sequences->next('receivable'), 'reservation_id' => $reservation->id, 'client_id' => $reservation->client_id,
            'concept' => $concept, 'amount' => $amount, 'currency' => $reservation->currency, 'due_date' => $due, 'status' => 'abierta',
        ]);
        $this->audit->log('Cuenta por cobrar', $receivable->code, 'crear', null, "{$reservation->code} · {$concept} · {$reservation->currency} {$amount} · vence {$due} · {$origin}", actor: $actor);

        return $receivable;
    }

    /** RES-BR-15: un delta negativo reduce las CxC abiertas en orden; el exceso queda como saldo a favor. */
    public function reduce(Reservation $reservation, string $amount, User $actor): string
    {
        $left = BigDecimal::of($amount);
        foreach ($reservation->receivables()->whereIn('status', Receivable::OPEN)->get() as $r) {
            if (! $left->isPositive()) {
                break;
            }
            $open = BigDecimal::of($r->balance());
            $take = $open->isLessThan($left) ? $open : $left;
            $newAmount = BigDecimal::of((string) $r->amount)->minus($take);
            $before = "{$r->currency} {$r->amount}";
            $covered = BigDecimal::of((string) $r->paid_amount)->isGreaterThanOrEqualTo($newAmount);
            $r->forceFill(['amount' => (string) $newAmount->toScale(2), 'status' => $covered ? 'pagada' : $r->status]);
            $r->save();
            $this->audit->log('Cuenta por cobrar', $r->code, 'ajustar', $before, "{$r->currency} {$r->amount} · {$r->status} · {$reservation->code}", actor: $actor);
            $left = $left->minus($take);
        }

        return (string) $left->toScale(2);
    }

    /** RES-BR-40 / RT-9: pago rápido aplicado; monto > 0 y ≤ saldo (+0.01). */
    public function applyPayment(Receivable $receivable, array $data, User $actor): Payment
    {
        if (! in_array($receivable->status, Receivable::OPEN, true)) {
            throw ApiException::rule('RES-BR-40', "La CxC {$receivable->code} no está abierta (estado {$receivable->status})");
        }
        $amount = BigDecimal::of((string) $data['amount']);
        $balance = BigDecimal::of($receivable->balance());
        if (! $amount->isPositive() || $amount->isGreaterThan($balance->plus('0.01'))) {
            throw ApiException::invalid('amount', "El monto debe ser mayor a 0 y no superar el saldo de la CxC ({$receivable->currency} {$balance}).");
        }
        $reservation = $receivable->reservation_id ? Reservation::find($receivable->reservation_id) : null;
        $payment = Payment::create([
            'code' => $this->sequences->next('payment'), 'receivable_id' => $receivable->id, 'reservation_id' => $receivable->reservation_id,
            'client_id' => $receivable->client_id, 'method' => $data['method'], 'amount' => (string) $amount->toScale(2), 'currency' => $receivable->currency,
            'paid_on' => $data['paid_on'] ?? $this->clock->today()->toDateString(), 'status' => 'aplicado', 'reference' => $data['reference'] ?? null,
            'kind' => 'cobro', 'registered_by' => $actor->id,
        ]);
        $paid = BigDecimal::of((string) $receivable->paid_amount)->plus($amount);
        $receivable->forceFill(['paid_amount' => (string) $paid->toScale(2), 'status' => $paid->isGreaterThanOrEqualTo((string) $receivable->amount) ? 'pagada' : 'parcial'])->save();
        $this->audit->log('Pago', $payment->code, 'registrar_aplicar', null, "{$receivable->code} · {$payment->method} · {$payment->currency} {$payment->amount}".($payment->reference ? " · {$payment->reference}" : ''), actor: $actor);

        if ($reservation) {
            if ($reservation->status === Reservation::PENDING_PAYMENT && BigDecimal::of($this->balance($reservation))->isLessThanOrEqualTo(self::SETTLED_TOLERANCE)) {
                $reservation->forceFill(['status' => Reservation::CONFIRMED])->save();
                $this->audit->log('Reserva', $reservation->code, 'cambio_estado', Reservation::STATUS_LABELS[Reservation::PENDING_PAYMENT], 'Confirmada · pago completo', actor: $actor);
            }
            if ($email = $reservation->client?->email) {
                $this->notify->push('pago.recibido', 'email', $email, "Recibimos su pago de {$payment->currency} {$payment->amount}", "Reserva {$reservation->code} · {$receivable->concept}",
                    ['client_id' => $reservation->client_id, 'related_type' => 'reservation', 'related_id' => $reservation->id]);
            }
        }

        return $payment;
    }

    /** Cobrado: pagos `aplicado|confirmado` de tipo cobro (§12-2). */
    public function paid(Reservation $reservation): string
    {
        return (string) BigDecimal::of((string) Payment::where('reservation_id', $reservation->id)->where('kind', 'cobro')->whereIn('status', Payment::COUNTED)->sum('amount'))->toScale(2);
    }

    public function balance(Reservation $reservation): string
    {
        return (string) BigDecimal::of((string) $reservation->total_amount)->minus($this->paid($reservation))->toScale(2);
    }

    /** §4.6 efecto 4: CxC no pagadas ni canceladas → canceladas. */
    public function cancelOpen(Reservation $reservation, User $actor): void
    {
        foreach ($reservation->receivables()->whereNotIn('status', ['pagada', 'cancelada'])->get() as $r) {
            $before = $r->status;
            $r->forceFill(['status' => 'cancelada'])->save();
            $this->audit->log('Cuenta por cobrar', $r->code, 'cancelar', $before, "cancelada · {$reservation->code}", actor: $actor);
        }
    }

    /** RES-BR-32: nota de crédito por el reembolso + pago de reembolso pendiente. @return array{0: Invoice, 1: Payment} */
    public function refund(Reservation $reservation, string $amount, string $penalty, User $actor): array
    {
        $today = $this->clock->today()->toDateString();
        $note = Invoice::create([
            'code' => $this->sequences->next('credit_note'), 'type' => 'Nota de crédito', 'status' => 'emitido', 'reservation_id' => $reservation->id,
            'client_id' => $reservation->client_id, 'amount' => $amount, 'currency' => $reservation->currency, 'issued_on' => $today,
            'detail' => "Anulación por cancelación de {$reservation->code} (penalidad {$reservation->currency} {$penalty})",
        ]);
        $this->audit->log('Nota de crédito', $note->code, 'emitir', null, "{$reservation->code} · {$note->currency} {$amount}", actor: $actor);
        $payment = Payment::create([
            'code' => $this->sequences->next('payment'), 'receivable_id' => null, 'reservation_id' => $reservation->id, 'client_id' => $reservation->client_id,
            'method' => 'Reembolso (mismo medio de pago)', 'amount' => $amount, 'currency' => $reservation->currency, 'paid_on' => $today,
            'status' => 'pendiente', 'reference' => "Reembolso {$note->code} · 7–15 días hábiles", 'kind' => 'reembolso', 'credit_note_id' => $note->id,
            'registered_by' => $actor->id,
        ]);
        $this->audit->log('Pago', $payment->code, 'registrar_reembolso', null, "{$reservation->code} · {$payment->currency} {$amount} · pendiente", actor: $actor);

        return [$note, $payment];
    }
}
