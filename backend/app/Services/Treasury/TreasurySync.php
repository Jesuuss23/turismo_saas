<?php

namespace App\Services\Treasury;

use App\Models\Finance\Payment;
use App\Models\Finance\PaymentMethod;
use App\Models\Finance\Payout;
use App\Models\Iam\User;
use App\Models\Treasury\Account;
use App\Models\Treasury\Movement;
use App\Services\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reflejo en tesorería de cobros, reversiones y pagos a proveedores (spec 12 §8, TES-BR-03/04): idempotente por `source_key`
 * (`PAG:`, `REV:`, `PPR:`). El crédito B2B no mueve caja. La sincronización nunca bloquea el documento de origen: si falla,
 * se registra y se reprocesa con POST /treasury/sync.
 */
class TreasurySync
{
    public function __construct(private readonly TreasuryLedger $ledger) {}

    public static function register(): void
    {
        Payment::saved(fn (Payment $p) => app(self::class)->safely(fn () => app(self::class)->payment($p)));
        Payout::created(fn (Payout $o) => app(self::class)->safely(fn () => app(self::class)->payout($o)));
    }

    /** Savepoint propio: un error aquí no aborta la transacción del pago. */
    public function safely(callable $fn): void
    {
        if (! app(TenantContext::class)->has()) {
            return;
        }
        try {
            DB::transaction($fn);
        } catch (Throwable $e) {
            Log::warning('Tesorería: no se pudo sincronizar', ['error' => $e->getMessage()]);
        }
    }

    /** Pago confirmado o aplicado → 1 ingreso; revertido → egreso; reembolso pagado → egreso. Devuelve los movimientos creados. */
    public function payment(Payment $payment): int
    {
        $created = 0;
        $code = $this->methodCode($payment->method);
        if ($code === null || $code === 'credito_b2b') {
            return 0;
        }
        $refund = $payment->kind === 'reembolso';
        $counted = in_array($payment->status, Payment::COUNTED, true);
        $reverted = $payment->status === 'revertido';
        if (($counted || $reverted) && ! $refund) {
            $created += $this->ensure("PAG:{$payment->code}", $payment, $code, 'in', 'collection', 'payment', "Cobro {$payment->code}");
        }
        if ($reverted && ! $refund) {
            $created += $this->ensure("REV:{$payment->code}", $payment, $code, 'out', 'collection_reversal', 'payment_reversal',
                "Reversión de {$payment->code}".($payment->revert_reason ? " · {$payment->revert_reason}" : ''));
        }
        if ($refund && $counted) {
            $created += $this->ensure("REV:{$payment->code}", $payment, $code, 'out', 'collection_reversal', 'payment_reversal', "Reembolso {$payment->code}");
        }

        return $created;
    }

    /** Payout de CxP → egreso de la cuenta bancaria del pago (o de la caja chica principal si fue en efectivo). */
    public function payout(Payout $payout): int
    {
        $key = "PPR:{$payout->code}";
        if (Movement::where('source_key', $key)->exists()) {
            return 0;
        }
        $payable = $payout->payable()->with('provider')->first();
        $currency = $payable?->currency ?? 'PEN';
        $account = $this->payoutAccount($payout, $currency);
        if (! $account) {
            return 0;
        }
        [$amount, $rate] = $this->ledger->convert((float) $payout->amount, $currency, $account->currency, $payout->fx_rate ? (float) $payout->fx_rate : null, $payout->paid_on?->toDateString());
        $this->ledger->post($account, [
            'movement_date' => $payout->paid_on?->toDateString(), 'direction' => 'out', 'type' => 'supplier_payment', 'amount' => $amount,
            'fx_rate' => $account->currency === 'USD' ? ($payout->fx_rate ?: null) : null,
            'orig_amount' => $currency !== $account->currency ? $payout->amount : null, 'orig_currency' => $currency !== $account->currency ? $currency : null,
            'orig_fx_rate' => $currency !== $account->currency ? $rate : null,
            'source_type' => 'payout', 'source_id' => $payout->id, 'source_key' => $key, 'source_code' => $payable?->code,
            'reservation_id' => $payable?->reservation_id, 'description' => "Pago {$payout->code} · {$payable?->code} · ".Str::limit((string) $payable?->concept, 120),
            'reference' => $payout->operation_ref, 'counterparty' => $payable?->provider?->name, 'created_by' => $payout->paid_by,
        ]);

        return 1;
    }

    /** POST /treasury/sync: reproceso manual (idempotente). */
    public function run(?User $actor = null): array
    {
        $before = Movement::count();
        Payment::orderBy('created_at')->each(fn (Payment $p) => $this->safely(fn () => $this->payment($p)));
        Payout::orderBy('created_at')->each(fn (Payout $o) => $this->safely(fn () => $this->payout($o)));
        $created = Movement::count() - $before;
        app(AuditLogger::class)->log('Tesorería/sync', 'sync', 'sincronizar_finanzas', null, "{$created} movimiento(s) nuevos", actor: $actor, system: $actor === null);

        return ['created' => $created];
    }

    /** Código del medio de pago a partir de la etiqueta guardada en el pago («Transferencia BCP», «Tarjeta (e-commerce)»…). */
    public function methodCode(?string $method): ?string
    {
        $method = trim((string) $method);
        if ($method === '') {
            return null;
        }
        $known = PaymentMethod::where('code', $method)->orWhere('label', $method)->value('code');
        if ($known) {
            return $known;
        }
        $m = Str::lower(Str::ascii($method));

        return match (true) {
            str_contains($m, 'credito') => 'credito_b2b',
            str_contains($m, 'tarjeta') => 'tarjeta',
            str_contains($m, 'yape') => 'yape',
            str_contains($m, 'plin') => 'plin',
            str_contains($m, 'interbank') => 'transferencia_interbank',
            str_contains($m, 'bbva') => 'transferencia_bbva',
            str_contains($m, 'efectivo') => 'efectivo',
            default => 'transferencia_bcp',
        };
    }

    /** Cuenta que recibe el medio de pago: la de la misma moneda primero (Yape USD → BCP PEN con monto original y TC). */
    public function accountFor(string $methodCode, string $currency): ?Account
    {
        $candidates = Account::where('status', 'active')->whereJsonContains('collect_methods', $methodCode)->orderBy('code')->get();
        if ($candidates->isEmpty()) {
            $candidates = Account::where('status', 'active')->where('kind', 'bank')->orderBy('code')->get();
        }

        return $candidates->firstWhere('currency', $currency) ?? $candidates->first();
    }

    private function ensure(string $key, Payment $payment, string $methodCode, string $direction, string $type, string $source, string $description): int
    {
        if (Movement::where('source_key', $key)->exists()) {
            return 0;
        }
        $account = $this->accountFor($methodCode, $payment->currency);
        if (! $account) {
            return 0;
        }
        $date = $direction === 'out' && $payment->reverted_at ? $payment->reverted_at->toDateString() : $payment->paid_on?->toDateString();
        $date = min($date ?? $this->ledger->today()->toDateString(), $this->ledger->today()->toDateString());
        [$amount, $rate] = $this->ledger->convert((float) $payment->amount, $payment->currency, $account->currency, $payment->fx_rate ? (float) $payment->fx_rate : null, $date);
        $foreign = $payment->currency !== $account->currency;
        $this->ledger->post($account, [
            'movement_date' => $date, 'direction' => $direction, 'type' => $type, 'amount' => $amount,
            'fx_rate' => $account->currency === 'USD' ? ($payment->fx_rate ?: null) : null,
            'orig_amount' => $foreign ? $payment->amount : null, 'orig_currency' => $foreign ? $payment->currency : null, 'orig_fx_rate' => $foreign ? $rate : null,
            'source_type' => $source, 'source_id' => $payment->id, 'source_key' => $key, 'source_code' => $payment->code,
            'reservation_id' => $payment->reservation_id, 'description' => $description, 'reference' => Str::limit((string) $payment->reference, 78, ''),
            'counterparty' => $payment->client?->name, 'created_by' => $direction === 'out' ? ($payment->reverted_by ?? $payment->registered_by) : $payment->registered_by,
        ]);

        return 1;
    }

    private function payoutAccount(Payout $payout, string $currency): ?Account
    {
        if ($payout->bank_account_id && ($a = Account::where('bank_account_id', $payout->bank_account_id)->where('status', 'active')->first())) {
            return $a;
        }
        if ($payout->method === 'efectivo_caja_chica') {
            return Account::where('kind', 'petty_cash')->where('status', 'active')->where('currency', $currency)->orderBy('code')->first()
                ?? Account::where('kind', 'petty_cash')->where('status', 'active')->orderBy('code')->first();
        }

        return Account::where('kind', 'bank')->where('status', 'active')->where('currency', $currency)->orderBy('code')->first()
            ?? Account::where('kind', 'bank')->where('status', 'active')->orderBy('code')->first();
    }
}
