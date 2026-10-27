<?php

namespace App\Services\Channels;

use App\Exceptions\ApiException;
use App\Models\Bookings\Reservation;
use App\Models\Channel\B2bAgency;
use App\Models\Channel\B2bCreditMovement;
use App\Models\Finance\Payment;
use App\Models\Finance\Receivable;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Finance\Fin;
use App\Services\Finance\FxRates;
use App\Services\Notify;

/**
 * Libro de la línea de crédito B2B (spec 08 §3.4, B2B-BR-12, §11-5): `credit_used` se mueve solo con movimientos.
 * Cargo al reservar a crédito; liberación al aplicar un pago a la CxC de esa reserva; reversa al revertirlo.
 * El método "Crédito B2B" de Finanzas carga la línea solo si la reserva no se cargó al reservar (sin doble conteo).
 */
class B2bCredit
{
    public function __construct(
        private readonly FxRates $fx,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /** Monto en la moneda de la línea (RT-15, §12-4) con el TC usado. @return array{0: float, 1: ?string} */
    public function toLine(B2bAgency $agency, float $amount, string $currency, mixed $fx = null): array
    {
        if ($currency === $agency->credit_currency) {
            return [Fin::r2($amount), null];
        }
        $pen = $this->fx->toPen($amount, $currency, $fx);
        if ($agency->credit_currency === 'PEN') {
            return [$pen, $this->fx->rateOf($currency, $fx)];
        }
        $rate = (float) $this->fx->rate($agency->credit_currency);

        return [Fin::r2($pen / $rate), number_format($rate, 6, '.', '')];
    }

    /** Agencia bloqueada para escribir el libro (FOR UPDATE, B2B-BR-04). */
    public function lock(B2bAgency|string $agency): B2bAgency
    {
        return B2bAgency::whereKey($agency instanceof B2bAgency ? $agency->id : $agency)->lockForUpdate()->firstOrFail();
    }

    public function record(B2bAgency $agency, string $type, float $amount, ?string $reservationId, ?string $paymentId, ?float $source, ?string $sourceCurrency,
        ?string $fx, ?string $note, ?User $actor): B2bCreditMovement
    {
        $used = Fin::r2(max(0, (float) $agency->credit_used + $amount));
        $wasExceeded = $agency->exceeded();
        $movement = B2bCreditMovement::create([
            'agency_id' => $agency->id, 'movement_type' => $type, 'reservation_id' => $reservationId, 'payment_id' => $paymentId,
            'amount' => Fin::money($amount), 'currency' => $agency->credit_currency, 'source_amount' => $source !== null ? Fin::money($source) : null,
            'source_currency' => $sourceCurrency, 'fx_rate' => $fx, 'balance_after' => Fin::money($used), 'note' => $note ? mb_substr($note, 0, 250) : null,
            'created_by' => $actor?->id,
        ]);
        $agency->forceFill(['credit_used' => Fin::money($used)])->save();
        // B2B-BR-08: exceder la línea bloquea el crédito (estado efectivo); el motivo queda registrado.
        if (! $wasExceeded && $agency->exceeded()) {
            $agency->forceFill(['block_reason' => 'credito_excedido'])->save();
            $this->audit->log('Agencia B2B', $agency->code, 'bloquear', 'Activa', "Bloqueada · crédito excedido ({$agency->credit_currency} {$agency->credit_used} / {$agency->credit_limit})", actor: $actor, system: $actor === null);
        }

        return $movement;
    }

    /** Cargo por reserva a crédito (B2B-BR-12). */
    public function chargeReservation(B2bAgency $agency, Reservation $reservation, ?User $actor): B2bCreditMovement
    {
        [$amount, $fx] = $this->toLine($agency, (float) $reservation->total_amount, $reservation->currency, $reservation->fx_rate);

        return $this->record($agency, 'cargo_reserva', $amount, $reservation->id, null, (float) $reservation->total_amount, $reservation->currency, $fx,
            "Reserva {$reservation->code}", $actor);
    }

    /** Saldo de la reserva aún cargado en la línea (cargos − liberaciones), en la moneda de la línea. */
    public function outstanding(string $agencyId, string $reservationId): float
    {
        return Fin::r2((float) B2bCreditMovement::where('agency_id', $agencyId)->where('reservation_id', $reservationId)->sum('amount'));
    }

    public function chargedAtBooking(string $agencyId, string $reservationId): bool
    {
        return B2bCreditMovement::where('agency_id', $agencyId)->where('reservation_id', $reservationId)->where('movement_type', 'cargo_reserva')
            ->whereNull('payment_id')->exists();
    }

    public function agencyFor(Receivable $receivable): ?B2bAgency
    {
        $reservation = $receivable->reservation_id ? Reservation::withTrashed()->find($receivable->reservation_id) : null;
        $id = $reservation?->agency_id ?? B2bAgency::where('client_id', $receivable->client_id)->value('id');

        return $id ? $this->lock($id) : null;
    }

    /**
     * FIN-BR-10 + B2B-BR-12: pago con "Crédito B2B". Si la reserva ya se cargó al reservar no vuelve a cargar.
     * Valida estado y disponible; el intento excedido se audita aunque falle.
     */
    public function useForPayment(Receivable $receivable, float $amount, string $currency, mixed $fx, ?User $actor): void
    {
        $agency = $this->agencyFor($receivable) ?? throw ApiException::unprocessable('RT-14', 'Regla transversal 14: el crédito B2B solo aplica a clientes que son agencias asociadas.');
        $status = $agency->effectiveStatus();
        if ($status !== 'activa') {
            throw ApiException::unprocessable('RT-14', "Regla transversal 14: la agencia {$agency->name} está ".mb_strtolower(B2bAgency::STATUS_LABELS[$status]).'; no puede usar su línea de crédito.');
        }
        if ($receivable->reservation_id && $this->chargedAtBooking($agency->id, $receivable->reservation_id)) {
            return;
        }
        [$line, $rate] = $this->toLine($agency, $amount, $currency, $fx);
        if ($line > (float) $agency->available() + Fin::EPS) {
            $this->audit->isolated('Agencia B2B', $agency->code, 'credito_excedido', "disponible {$agency->credit_currency} {$agency->available()}", "intento {$agency->credit_currency} ".Fin::money($line), actor: $actor);
            throw ApiException::unprocessable('FIN-BR-10', "Crédito B2B insuficiente: disponible {$agency->credit_currency} {$agency->available()}, requerido {$agency->credit_currency} ".Fin::money($line).'.');
        }
        $this->record($agency, 'pago_credito', $line, $receivable->reservation_id, null, $amount, $currency, $rate, "Pago con Crédito B2B · {$receivable->code}", $actor);
    }

    /** §8.1 `PaymentApplied`: un pago real aplicado a la CxC de una reserva cargada a crédito libera la línea. */
    public function onPaymentApplied(Payment $payment, Receivable $receivable, bool $creditMethod, ?User $actor): void
    {
        if ($creditMethod || ! $receivable->reservation_id) {
            return;
        }
        $agency = $this->agencyFor($receivable);
        if (! $agency) {
            return;
        }
        $outstanding = $this->outstanding($agency->id, $receivable->reservation_id);
        if ($outstanding <= Fin::EPS) {
            return;
        }
        [$line, $rate] = $this->toLine($agency, (float) $payment->amount, $payment->currency, $payment->fx_rate);
        $release = min($line, $outstanding);
        $this->record($agency, 'pago_aplicado', -$release, $receivable->reservation_id, $payment->id, (float) $payment->amount, $payment->currency, $rate,
            "Pago {$payment->code} aplicado a {$receivable->code}", $actor);
    }

    /** Reversión: devuelve a la línea lo que el pago había movido (cargo con Crédito B2B o liberación). */
    public function onPaymentReverted(Payment $payment, ?Receivable $receivable, bool $creditMethod, ?User $actor): void
    {
        if (! $receivable) {
            return;
        }
        $agency = $this->agencyFor($receivable);
        if (! $agency) {
            return;
        }
        if ($creditMethod) {
            if ($receivable->reservation_id && $this->chargedAtBooking($agency->id, $receivable->reservation_id)) {
                return;
            }
            [$line, $rate] = $this->toLine($agency, (float) $payment->amount, $payment->currency, $payment->fx_rate);
            $this->record($agency, 'reversion_pago', -$line, $receivable->reservation_id, $payment->id, (float) $payment->amount, $payment->currency, $rate,
                "Reversión del pago {$payment->code} (Crédito B2B)", $actor);

            return;
        }
        $released = -Fin::r2((float) B2bCreditMovement::where('payment_id', $payment->id)->where('movement_type', 'pago_aplicado')->sum('amount'));
        if ($released > Fin::EPS) {
            $this->record($agency, 'reversion_pago', $released, $receivable->reservation_id, $payment->id, (float) $payment->amount, $payment->currency, null,
                "Reversión del pago {$payment->code}", $actor);
        }
    }

    /** Anulación de una reserva cargada: libera lo pendiente. */
    public function releaseReservation(Reservation $reservation, ?User $actor): void
    {
        if (! $reservation->agency_id) {
            return;
        }
        $agency = $this->lock($reservation->agency_id);
        $outstanding = $this->outstanding($agency->id, $reservation->id);
        if ($outstanding > Fin::EPS) {
            $this->record($agency, 'anulacion_reserva', -$outstanding, $reservation->id, null, null, null, null, "Anulación de {$reservation->code}", $actor);
        }
    }

    /** §8.2 `RecalculateB2bCredit`: compara credit_used con la suma del libro. @return list<array> diferencias */
    public function verify(): array
    {
        $diffs = [];
        foreach (B2bAgency::all() as $agency) {
            $sum = Fin::r2(max(0, (float) B2bCreditMovement::where('agency_id', $agency->id)->sum('amount')));
            $last = B2bCreditMovement::where('agency_id', $agency->id)->exists();
            if ($last && abs($sum - (float) $agency->credit_used) > Fin::EPS) {
                $diffs[] = ['agency' => $agency->code, 'credit_used' => (string) $agency->credit_used, 'ledger' => Fin::money($sum)];
            }
        }

        return $diffs;
    }
}
