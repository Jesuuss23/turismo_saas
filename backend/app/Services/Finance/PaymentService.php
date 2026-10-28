<?php

namespace App\Services\Finance;

use App\Exceptions\ApiException;
use App\Models\Bookings\Reservation;
use App\Models\Finance\BankLine;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentApplication;
use App\Models\Finance\PaymentMethod;
use App\Models\Finance\Receivable;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Channels\B2bCredit;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use App\Services\SequenceService;
use App\Services\Site\SiteCheckout;
use Illuminate\Support\Facades\DB;

/**
 * Pagos de clientes (PROC-042/043, FIN-BR-07…17): registrar y aplicar en un paso, validar los pendientes de otros canales,
 * rechazar y reintentar, aplicar a una CxC y revertir. Crédito B2B con su línea vía el libro de Canales (FIN-BR-10, B2B-BR-12).
 */
class PaymentService
{
    private bool $bankContext = false;

    public function __construct(
        private readonly ReceivableLedger $ledger,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
        private readonly CrmClock $clock,
        private readonly FxRates $fx,
        private readonly B2bCredit $credit,
    ) {}

    /** Medio de pago activo por código o etiqueta (los formularios envían la etiqueta). */
    public function method(?string $value): PaymentMethod
    {
        $value = trim((string) $value);

        return PaymentMethod::where('active', true)->where(fn ($q) => $q->where('code', $value)->orWhere('label', $value))->first()
            ?? throw ApiException::invalid('method', 'Elija un medio de pago válido.');
    }

    /** FIN-BR-11: registro back-office = confirmado + aplicado en una sola operación (RT-09, FIN-BR-07/08/09/10). */
    public function register(Receivable $receivable, array $data, User $actor, string $source = 'backoffice'): Payment
    {
        $method = $this->method($data['method'] ?? null);
        $amount = Fin::r2($data['amount'] ?? 0);
        $reference = trim((string) ($data['reference'] ?? '')) ?: null;
        if ($amount <= 0) {
            throw ApiException::invalid('amount', 'El monto debe ser mayor a 0.');
        }
        if ($method->requires_reference && ! $reference) {
            throw ApiException::invalid('reference', "Indique el N° de operación del pago con {$method->label} (FIN-BR-08).");
        }
        if (! empty($data['paid_on']) && $data['paid_on'] > $this->clock->today()->toDateString()) {
            throw ApiException::invalid('paid_on', 'La fecha de pago no puede ser futura.');
        }

        return DB::transaction(function () use ($receivable, $data, $actor, $source, $method, $amount, $reference) {
            $receivable = Receivable::whereKey($receivable->id)->lockForUpdate()->firstOrFail();
            $this->assertObligation($receivable);
            if ($amount > (float) $receivable->balance() + Fin::EPS) {
                throw ApiException::unprocessable('PROC-042', "PROC-042: el monto ({$receivable->currency} ".Fin::money($amount).") no puede exceder el saldo pendiente ({$receivable->currency} {$receivable->balance()}).");
            }
            $fx = $receivable->currency === 'PEN' ? null : (string) ($data['fx_rate'] ?? $this->fx->rate($receivable->currency));
            if ($method->is_b2b_credit) {
                $this->credit->useForPayment($receivable, $amount, $receivable->currency, $fx, $actor);
            }
            $payment = Payment::create([
                'code' => $this->sequences->next('payment'), 'receivable_id' => $receivable->id, 'reservation_id' => $receivable->reservation_id,
                'client_id' => $receivable->client_id, 'kind' => 'cobro', 'source' => $source, 'method' => $method->label, 'amount' => Fin::money($amount),
                'currency' => $receivable->currency, 'fx_rate' => $fx, 'paid_on' => $data['paid_on'] ?? $this->clock->today()->toDateString(),
                'reference' => $reference ?? ($method->code === 'efectivo' ? 'Caja sede' : ($method->is_b2b_credit ? 'Línea B2B' : null)),
                'note' => trim((string) ($data['note'] ?? '')) ?: null, 'status' => 'confirmado', 'registered_by' => $actor->id,
            ]);
            Fin::history('payment', $payment->id, 'confirmado', 'Pago registrado y validado', $actor);
            $this->audit->log('Pago', $payment->code, 'registrar', null, "{$payment->method} · {$payment->currency} {$payment->amount}".($fx ? " · TC {$fx}" : '').($reference ? " · {$reference}" : ''), actor: $actor);

            return $this->applyTo($payment, $receivable, $actor);
        });
    }

    /** FIN-BR-12: solo pagos `pendiente`; `apply` = confirmar y aplicar a la CxC del pago (o a la indicada). */
    public function validate(Payment $payment, bool $apply, ?string $receivableId, User $actor, ?string $note = null): Payment
    {
        return DB::transaction(function () use ($payment, $apply, $receivableId, $actor, $note) {
            $payment = $this->lock($payment);
            $this->assertStatus($payment, ['pendiente'], 'validar');
            $payment->forceFill(['status' => 'confirmado'])->save();
            Fin::history('payment', $payment->id, 'confirmado', $note ?: 'Validado contra el extracto', $actor);
            $this->audit->log('Pago', $payment->code, 'validar', 'Pendiente', 'Confirmado'.($note ? " · {$note}" : ''), actor: $actor);
            if (! $apply) {
                return $payment->fresh();
            }
            $target = Receivable::whereKey($receivableId ?: $payment->receivable_id)->lockForUpdate()->first()
                ?? throw ApiException::unprocessable('RT-09', 'Regla transversal 9: el pago debe asociarse a una obligación.');

            return $this->applyTo($payment, $target, $actor);
        });
    }

    /** FIN-BR-53: el match bancario confirma un pago `pendiente` (sin aplicarlo). El If-Match de la petición es de la línea, no del pago. */
    public function confirmFromBank(Payment $payment, User $actor, string $note): Payment
    {
        $this->bankContext = true;
        try {
            return $this->validate($payment, false, null, $actor, $note);
        } finally {
            $this->bankContext = false;
        }
    }

    /** FIN-BR-13: rechazo con motivo; la CxC se mantiene y se avisa al cliente. */
    public function reject(Payment $payment, string $reason, User $actor): Payment
    {
        if (trim($reason) === '') {
            throw ApiException::invalid('reason', 'Indique el motivo del rechazo.');
        }

        return DB::transaction(function () use ($payment, $reason, $actor) {
            $payment = $this->lock($payment);
            $this->assertStatus($payment, ['pendiente'], 'rechazar');
            $payment->forceFill(['status' => 'rechazado', 'reject_reason' => trim($reason)])->save();
            Fin::history('payment', $payment->id, 'rechazado', trim($reason), $actor);
            $this->audit->log('Pago', $payment->code, 'rechazar', 'Pendiente', 'Rechazado · '.trim($reason), actor: $actor);
            $this->notifyClient($payment, 'pago.rechazado', "Pago {$payment->code} no validado: ".trim($reason).'. La obligación sigue pendiente.');

            return $payment->fresh();
        });
    }

    /** Reintento de validación: `rechazado → pendiente`, `retries += 1`. */
    public function retry(Payment $payment, User $actor): Payment
    {
        return DB::transaction(function () use ($payment, $actor) {
            $payment = $this->lock($payment);
            $this->assertStatus($payment, ['rechazado'], 'reintentar');
            $payment->forceFill(['status' => 'pendiente', 'retries' => $payment->retries + 1, 'reject_reason' => null])->save();
            Fin::history('payment', $payment->id, 'pendiente', "Reintento #{$payment->retries}", $actor);
            $this->audit->log('Pago', $payment->code, 'reintentar', 'Rechazado', "Pendiente · reintento #{$payment->retries}", actor: $actor);

            return $payment->fresh();
        });
    }

    /** FIN-BR-14: solo `confirmado`; CxC de la misma reserva y moneda; sin aplicación parcial. */
    public function apply(Payment $payment, Receivable $receivable, User $actor): Payment
    {
        return DB::transaction(function () use ($payment, $receivable, $actor) {
            $payment = $this->lock($payment);
            $receivable = Receivable::whereKey($receivable->id)->lockForUpdate()->firstOrFail();

            return $this->applyTo($payment, $receivable, $actor);
        });
    }

    /** FIN-BR-16: desde `confirmado` o `aplicado`, motivo obligatorio; desaplica, recalcula y devuelve el crédito B2B. */
    public function revert(Payment $payment, string $reason, User $actor): Payment
    {
        if (trim($reason) === '') {
            throw ApiException::invalid('reason', 'Indique el motivo de la reversión.');
        }

        return DB::transaction(function () use ($payment, $reason, $actor) {
            $payment = $this->lock($payment);
            $this->assertStatus($payment, ['confirmado', 'aplicado'], 'revertir');
            $before = Payment::STATUS_LABELS[$payment->status];
            $receivable = $payment->receivable_id ? Receivable::whereKey($payment->receivable_id)->lockForUpdate()->first() : null;
            PaymentApplication::where('payment_id', $payment->id)->whereNull('reversed_at')->update(['reversed_at' => now(), 'reversed_by' => $actor->id]);
            $payment->forceFill(['status' => 'revertido', 'reverted_at' => now(), 'reverted_by' => $actor->id, 'revert_reason' => trim($reason)])->save();
            $this->credit->onPaymentReverted($payment, $receivable, $this->isCreditMethod($payment), $actor);
            Fin::history('payment', $payment->id, 'revertido', trim($reason), $actor);
            $this->audit->log('Pago', $payment->code, 'revertir', $before, 'Revertido · '.trim($reason).($receivable ? " · {$receivable->code}" : ''), actor: $actor);
            if ($receivable) {
                $this->ledger->recalc($receivable, $actor, 'recalcular_saldo');
            }
            $this->notifyClient($payment, 'pago.revertido', "Reversión del pago {$payment->code} — {$payment->currency} {$payment->amount}");

            return $payment->fresh();
        });
    }

    /** Detalle del modal de validación: ¿el abono figura en el extracto? (FIN-BR-12). */
    public function bankEvidence(Payment $payment): ?BankLine
    {
        return BankLine::where('matched_payment_id', $payment->id)->where('status', '<>', 'excepcion')->first();
    }

    private function applyTo(Payment $payment, Receivable $receivable, User $actor): Payment
    {
        $this->assertStatus($payment, ['confirmado'], 'aplicar');
        $this->assertObligation($receivable);
        if ($payment->reservation_id && $receivable->reservation_id !== $payment->reservation_id) {
            throw ApiException::unprocessable('RT-09', 'Regla transversal 9: el pago solo se aplica a obligaciones de su misma reserva.');
        }
        if ($receivable->currency !== $payment->currency) {
            throw ApiException::unprocessable('FIN-BR-14', "La CxC {$receivable->code} está en {$receivable->currency} y el pago en {$payment->currency}.");
        }
        if ((float) $payment->amount > (float) $receivable->balance() + Fin::EPS) {
            throw ApiException::rule('FIN-BR-14', "El pago ({$payment->currency} {$payment->amount}) excede el saldo de {$receivable->code} ({$receivable->currency} {$receivable->balance()}).");
        }
        PaymentApplication::create(['payment_id' => $payment->id, 'receivable_id' => $receivable->id, 'amount' => $payment->amount, 'applied_at' => now(), 'applied_by' => $actor->id]);
        $payment->forceFill(['status' => 'aplicado', 'receivable_id' => $receivable->id, 'reservation_id' => $receivable->reservation_id])->save();
        Fin::history('payment', $payment->id, 'aplicado', "Aplicado a {$receivable->code}", $actor);
        $this->audit->log('Pago', $payment->code, 'aplicar', 'Confirmado', "Aplicado a {$receivable->code} · {$payment->currency} {$payment->amount}", actor: $actor);
        $this->ledger->recalc($receivable, $actor);
        $this->ledger->syncReservation($receivable->reservation_id ? Reservation::find($receivable->reservation_id) : null, $actor);
        $this->credit->onPaymentApplied($payment, $receivable, $this->isCreditMethod($payment), $actor);
        // Spec 15 §4.2/§4.4: pedido web por validar → confirmado; saldo completado → vouchers.
        if ($receivable->reservation_id) {
            app(SiteCheckout::class)->onPaymentApplied($payment, $actor);
        }
        $this->notifyClient($payment, 'pago.recibido', "Pago recibido {$payment->code} — {$payment->currency} {$payment->amount} ({$receivable->code})");

        return $payment->fresh();
    }

    /** RT-09: el pago se asocia a una obligación con saldo, no cancelada. */
    private function assertObligation(Receivable $receivable): void
    {
        if ($receivable->status === 'cancelada' || (float) $receivable->balance() <= Fin::EPS) {
            throw ApiException::unprocessable('RT-09', "Regla transversal 9: el pago debe asociarse a una obligación con saldo ({$receivable->code} está ".mb_strtolower(Receivable::STATUS_LABELS[$receivable->status]).').');
        }
    }

    private function assertStatus(Payment $payment, array $allowed, string $action): void
    {
        if (! in_array($payment->status, $allowed, true)) {
            throw ApiException::rule('FIN-BR-'.match ($action) {
                'aplicar' => '14', 'revertir' => '16', 'rechazar', 'reintentar' => '13', default => '12'
            },
                "No se puede {$action} el pago {$payment->code}: está ".mb_strtolower(Payment::STATUS_LABELS[$payment->status]).'.');
        }
    }

    private function lock(Payment $payment): Payment
    {
        $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
        if (! $this->bankContext && $payment->exists && request()?->hasHeader('If-Match')) {
            $locked->assertIfMatch(request());
        }

        return $locked;
    }

    private function isCreditMethod(Payment $payment): bool
    {
        return $payment->method === PaymentMethod::where('is_b2b_credit', true)->value('label');
    }

    private function notifyClient(Payment $payment, string $event, string $subject): void
    {
        $client = $payment->client;
        if ($client?->email) {
            $this->notify->push($event, 'email', $client->email, $subject, $payment->reservation?->code,
                ['client_id' => $client->id, 'related_type' => 'payment', 'related_id' => $payment->id]);
        }
    }
}
