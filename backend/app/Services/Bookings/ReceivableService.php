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
use App\Services\Finance\Fin;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PaymentService;
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

    public function create(Reservation $reservation, string $concept, string $amount, string $due, User $actor, string $origin, ?string $kind = null, mixed $fx = null,
        string $action = 'crear'): Receivable
    {
        $receivable = Receivable::create([
            'code' => $this->sequences->next('receivable'), 'reservation_id' => $reservation->id, 'client_id' => $reservation->client_id,
            'kind' => $kind ?? Receivable::kindFor($concept), 'concept' => $concept, 'amount' => $amount, 'currency' => $reservation->currency,
            'fx_rate' => $reservation->currency === 'PEN' ? null : (string) ($fx ?? $reservation->fx_rate), 'due_date' => $due, 'status' => 'abierta',
        ]);
        $this->audit->log('Cuenta por cobrar', $receivable->code, $action, null, "{$reservation->code} · {$concept} · {$reservation->currency} {$amount} · vence {$due} · {$origin}", actor: $actor);

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

    /** RES-BR-40 / RT-9: pago rápido aplicado desde la reserva; usa el mismo registro atómico que Finanzas (FIN-BR-11). */
    public function applyPayment(Receivable $receivable, array $data, User $actor): Payment
    {
        return app(PaymentService::class)->register($receivable, $data, $actor, $data['source'] ?? 'reserva');
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
            $r->forceFill(['status' => 'cancelada', 'cancelled_at' => now(), 'cancel_reason' => "Cancelación de la reserva {$reservation->code}"])->save();
            $this->audit->log('Cuenta por cobrar', $r->code, 'cancelar', $before, "cancelada · {$reservation->code}", actor: $actor);
        }
    }

    /**
     * RES-BR-32: reembolso pendiente y, si la reserva tiene comprobantes aceptados, nota de crédito emitida por Facturación
     * (serie FC01/BC01, validación fiscal y envío al OSE; spec 07 §12 P-10). @return array{0: ?Invoice, 1: Payment}
     */
    public function refund(Reservation $reservation, string $amount, string $penalty, User $actor): array
    {
        $note = app(InvoiceService::class)->creditNoteForCancellation($reservation, $amount, "Anulación por cancelación de {$reservation->code} (penalidad {$reservation->currency} {$penalty})", $actor);
        $payment = Payment::create([
            'code' => $this->sequences->next('payment'), 'receivable_id' => null, 'reservation_id' => $reservation->id, 'client_id' => $reservation->client_id,
            'method' => 'Reembolso (mismo medio de pago)', 'amount' => $amount, 'currency' => $reservation->currency,
            'fx_rate' => $reservation->currency === 'PEN' ? null : (string) $reservation->fx_rate, 'paid_on' => $this->clock->today()->toDateString(),
            'status' => 'pendiente', 'reference' => 'Reembolso '.($note ? "{$note->number} · " : '').'7–15 días hábiles', 'kind' => 'reembolso', 'source' => 'reserva',
            'credit_note_id' => $note?->id, 'registered_by' => $actor->id,
        ]);
        Fin::history('payment', $payment->id, 'pendiente', 'Reembolso por cancelación de '.$reservation->code, $actor);
        $this->audit->log('Pago', $payment->code, 'registrar_reembolso', null, "{$reservation->code} · {$payment->currency} {$amount} · pendiente", actor: $actor);

        return [$note, $payment];
    }
}
