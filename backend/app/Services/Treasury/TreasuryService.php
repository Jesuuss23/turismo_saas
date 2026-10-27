<?php

namespace App\Services\Treasury;

use App\Exceptions\ApiException;
use App\Models\Finance\BankAccount;
use App\Models\Iam\Sede;
use App\Models\Iam\User;
use App\Models\Treasury\Account;
use App\Models\Treasury\CashSession;
use App\Models\Treasury\Movement;
use App\Models\Treasury\Replenishment;
use App\Models\Treasury\ReplenishmentEvent;
use App\Models\Treasury\Transfer;
use App\Services\AuditLogger;
use App\Services\SequenceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cuentas, movimientos manuales, anulación, transferencias, depósitos de efectivo y liquidación de la pasarela
 * (spec 12 §4 Movimiento, §5 TES-BR-01/02/03/05/10/17, §6).
 */
class TreasuryService
{
    public function __construct(
        private readonly TreasuryLedger $ledger,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
    ) {}

    /** POST /treasury/accounts (admin.settings.manage). */
    public function createAccount(array $data, User $actor): Account
    {
        $attrs = $this->accountAttributes($data);
        try {
            $account = DB::transaction(fn () => Account::create($attrs + ['code' => $this->sequences->next('treasury_account'), 'status' => 'active']));
        } catch (QueryException $e) {
            throw $this->duplicateOr($e);
        }
        $this->audit->log('Cuenta de tesorería', $account->code, 'crear', null, "{$account->name} · {$account->currency} · saldo inicial {$account->opening_balance}", actor: $actor);

        return $account;
    }

    /** PATCH /treasury/accounts/{id} (If-Match). El saldo inicial no cambia si ya hay movimientos. */
    public function updateAccount(Account $account, array $data, User $actor): Account
    {
        $attrs = $this->accountAttributes($data + $account->only(['kind', 'name', 'currency', 'sede_id', 'opening_date']) + ['opening_balance' => $account->opening_balance], $account);
        if (Movement::where('account_id', $account->id)->exists()) {
            unset($attrs['opening_balance'], $attrs['opening_rate'], $attrs['opening_date'], $attrs['currency'], $attrs['kind']);
        }
        if (isset($data['status'])) {
            if (! in_array($data['status'], ['active', 'inactive'], true)) {
                throw ApiException::invalid('status', 'Estado inválido.');
            }
            if ($data['status'] === 'inactive' && abs($this->ledger->balance($account)) > 0.004) {
                throw ApiException::unprocessable('TES-ACCOUNT', 'Solo se desactiva una cuenta con saldo cero.');
            }
            $attrs['status'] = $data['status'];
        }
        $before = "{$account->name} · {$account->status}";
        try {
            $account->fill($attrs)->save();
        } catch (QueryException $e) {
            throw $this->duplicateOr($e);
        }
        $this->audit->log('Cuenta de tesorería', $account->code, 'editar', $before, "{$account->name} · {$account->status}", actor: $actor);

        return $account->fresh();
    }

    /** POST /treasury/movements: otro ingreso o egreso manual (TES-BR-01/02/05). */
    public function manual(array $data, User $actor): Movement
    {
        $direction = (string) ($data['direction'] ?? '');
        if (! in_array($direction, ['in', 'out'], true)) {
            throw ApiException::invalid('direction', 'Indique si es ingreso o egreso.');
        }
        $amount = $this->amount($data['amount'] ?? null);
        $date = $this->date($data['movement_date'] ?? null);
        $description = trim((string) ($data['description'] ?? ''));
        if (mb_strlen($description) < 3) {
            throw ApiException::invalid('description', 'Describa el movimiento.');
        }
        $account = $this->account($data['account_id'] ?? null);

        $movement = DB::transaction(function () use ($account, $direction, $amount, $date, $description, $data) {
            $account = $this->ledger->lock($account->id)->first();
            if ($account->isPetty()) {
                $this->assertSessionOpen($account);
            }
            if ($direction === 'out' && $account->kind !== 'gateway') {
                $this->ledger->assertCovers($account, $amount);
            }

            return $this->ledger->post($account, [
                'movement_date' => $date, 'direction' => $direction, 'type' => $direction === 'in' ? 'other_income' : 'other_expense', 'amount' => $amount,
                'source_type' => 'manual', 'category' => $this->str($data['category'] ?? null, 60), 'description' => mb_substr($description, 0, 255),
                'reference' => $this->str($data['reference'] ?? null, 80), 'counterparty' => $this->str($data['counterparty'] ?? null, 160),
                'fx_rate' => $account->currency === 'USD' && ! empty($data['fx_rate']) ? (float) $data['fx_rate'] : null,
            ]);
        });
        $this->audit->log('Movimiento de tesorería', $movement->code, 'registrar', null,
            ($direction === 'in' ? 'Ingreso' : 'Egreso')." {$account->currency} {$movement->amount} · {$account->code} · {$description}", actor: $actor);

        return $movement;
    }

    /** POST /treasury/movements/{id}/void: manuales, gastos no rendidos, transferencias (todas sus partidas) y liquidaciones. */
    public function void(Movement $movement, string $reason, User $actor): Movement
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ApiException::invalid('reason', 'Indique el motivo de la anulación (mínimo 5 caracteres).');
        }
        if ($movement->status === 'voided') {
            throw ApiException::conflict('ALREADY_VOIDED', 'El movimiento ya está anulado.');
        }
        if ($movement->isSynced()) {
            throw ApiException::unprocessable('TES-BR-03', 'Los movimientos sincronizados desde Finanzas se corrigen en el documento de origen (pago, reversión o CxP).');
        }
        if (in_array($movement->source_type, ['cash_count', 'replenishment'], true) && ! $movement->transfer_id) {
            throw ApiException::unprocessable('TES-VOID', 'Este movimiento nace de un arqueo y no se anula.');
        }
        if ($movement->replenishment_id && $movement->type === 'petty_expense') {
            throw ApiException::unprocessable('TES-BR-10', 'El gasto ya está incluido en una reposición: no se anula.');
        }

        DB::transaction(function () use ($movement, $reason, $actor) {
            $group = $movement->transfer_id ? Movement::where('transfer_id', $movement->transfer_id)->where('status', 'posted')->get() : collect([$movement]);
            $accounts = $this->ledger->lock(...$group->pluck('account_id')->all());
            // TES-BR-02: anular un ingreso no puede dejar en sobregiro a la cuenta que lo recibió.
            foreach ($group->where('direction', 'in')->groupBy('account_id') as $accountId => $rows) {
                $account = $accounts[$accountId];
                if ($account->kind !== 'gateway') {
                    $this->ledger->assertCovers($account, (float) $rows->sum('amount'), 'La anulación');
                }
            }
            foreach ($group as $m) {
                $m->forceFill(['status' => 'voided', 'void_reason' => $reason, 'voided_by' => $actor->id, 'voided_at' => now()])->save();
            }
            if ($movement->transfer_id) {
                $transfer = Transfer::find($movement->transfer_id);
                $transfer->forceFill(['status' => 'voided'])->save();
                Movement::where('deposited_transfer_id', $transfer->id)->update(['deposited_transfer_id' => null]);
                // §4 Reposición: anular la transferencia de pago la devuelve a «aprobada».
                if ($rep = Replenishment::where('transfer_id', $transfer->id)->first()) {
                    $rep->forceFill(['status' => 'approved', 'paid_by' => null, 'paid_at' => null, 'paid_from_account_id' => null, 'transfer_id' => null])->save();
                    ReplenishmentEvent::create(['replenishment_id' => $rep->id, 'status' => 'approved', 'message' => "Pago anulado ({$transfer->code}): {$reason}",
                        'user_id' => $actor->id, 'created_at' => now()]);
                }
            }
        });
        $this->audit->log('Movimiento de tesorería', $movement->code, 'anular', "{$movement->currency} {$movement->amount} · registrado", "Anulado: {$reason}", actor: $actor);

        return $movement->fresh();
    }

    /** POST /treasury/transfers: transferencia entre cuentas o depósito de efectivo (TES-BR-02, locks ordenados). */
    public function transfer(array $data, User $actor, string $kind = 'transfer'): Transfer
    {
        $kind = in_array($data['kind'] ?? $kind, ['transfer', 'cash_deposit'], true) ? ($data['kind'] ?? $kind) : 'transfer';
        $from = $this->account($data['from_account_id'] ?? null, 'from_account_id');
        $to = $this->account($data['to_account_id'] ?? null, 'to_account_id');
        if ($from->id === $to->id) {
            throw ApiException::invalid('to_account_id', 'Elija una cuenta destino distinta.');
        }
        if ($kind === 'cash_deposit' && (! $from->isPetty() || $to->kind !== 'bank')) {
            throw ApiException::invalid('to_account_id', 'El depósito de efectivo va de una caja a una cuenta bancaria.');
        }
        if ($to->kind === 'gateway' || $from->kind === 'gateway') {
            throw ApiException::invalid('from_account_id', 'La pasarela se liquida con «Liquidar pasarela».');
        }
        $amount = $this->amount($data['amount_from'] ?? $data['amount'] ?? null, 'amount_from');
        $date = $this->date($data['transfer_date'] ?? null);
        $reference = trim((string) ($data['reference'] ?? ''));
        if ($reference === '') {
            throw ApiException::invalid('reference', 'Indique el N° de operación.');
        }
        $rate = null;
        $amountTo = $amount;
        if ($from->currency !== $to->currency) {
            $rate = (float) ($data['deal_rate'] ?? 0);
            if ($rate <= 0) {
                throw ApiException::invalid('deal_rate', 'Indique el tipo de cambio pactado.');
            }
            $amountTo = round($from->currency === 'USD' ? $amount * $rate : $amount / $rate, 2);
        }

        $transfer = DB::transaction(function () use ($from, $to, $amount, $amountTo, $rate, $date, $reference, $kind, $data) {
            $locked = $this->ledger->lock($from->id, $to->id);
            $from = $locked[$from->id];
            $to = $locked[$to->id];
            if ($from->isPetty()) {
                $this->assertSessionOpen($from);
            }
            $this->ledger->assertCovers($from, $amount, 'La transferencia');
            $t = Transfer::create(['code' => $this->sequences->next('treasury_transfer'), 'from_account_id' => $from->id, 'to_account_id' => $to->id,
                'amount_from' => $amount, 'amount_to' => $amountTo, 'deal_rate' => $rate, 'kind' => $kind, 'transfer_date' => $date, 'reference' => $reference]);
            $note = $this->str($data['description'] ?? null, 160);
            $label = $kind === 'cash_deposit' ? 'Depósito de efectivo' : 'Transferencia';
            $this->ledger->post($from, ['movement_date' => $date, 'direction' => 'out', 'type' => 'transfer', 'amount' => $amount, 'source_type' => 'transfer',
                'source_id' => $t->id, 'source_code' => $t->code, 'transfer_id' => $t->id, 'reference' => $reference, 'counterparty' => $to->name,
                'description' => "{$label} {$t->code} a {$to->code}".($note ? " · {$note}" : ''), 'fx_rate' => $from->currency === 'USD' ? $rate : null]);
            $this->ledger->post($to, ['movement_date' => $date, 'direction' => 'in', 'type' => 'transfer', 'amount' => $amountTo, 'source_type' => 'transfer',
                'source_id' => $t->id, 'source_code' => $t->code, 'transfer_id' => $t->id, 'reference' => $reference, 'counterparty' => $from->name,
                'description' => "{$label} {$t->code} desde {$from->code}".($note ? " · {$note}" : ''), 'fx_rate' => $to->currency === 'USD' ? $rate : null,
                'orig_amount' => $rate ? $amount : null, 'orig_currency' => $rate ? $from->currency : null, 'orig_fx_rate' => $rate]);
            if ($kind === 'cash_deposit') {
                $this->markDeposited($from, $t, $amount);
            }

            return $t;
        });
        $this->audit->log('Transferencia', $transfer->code, 'registrar', null,
            "{$from->code} → {$to->code} · {$from->currency} {$transfer->amount_from}".($rate ? " → {$to->currency} {$transfer->amount_to} (TC {$rate})" : '')." · op. {$reference}", actor: $actor);

        return $transfer;
    }

    /** POST /treasury/gateway/{account}/settlements: el neto pasa al banco y la comisión queda como gasto de la pasarela. */
    public function settleGateway(Account $gateway, array $data, User $actor): Transfer
    {
        if ($gateway->kind !== 'gateway') {
            throw ApiException::invalid('account', 'La cuenta no es una pasarela.');
        }
        $gross = $this->amount($data['gross_amount'] ?? null, 'gross_amount');
        $pct = isset($data['fee_pct']) && $data['fee_pct'] !== '' ? (float) $data['fee_pct'] : (float) $this->ledger->settings()->gateway_fee_pct;
        if ($pct < 0 || $pct > 20) {
            throw ApiException::invalid('fee_pct', 'La comisión debe estar entre 0 % y 20 %.');
        }
        $batch = trim((string) ($data['batch'] ?? ''));
        if ($batch === '') {
            throw ApiException::invalid('batch', 'Indique el lote de la liquidación.');
        }
        $date = $this->date($data['settlement_date'] ?? null);
        $fee = round($gross * $pct / 100, 2);
        $net = round($gross - $fee, 2);

        $transfer = DB::transaction(function () use ($gateway, $gross, $fee, $net, $batch, $date, $pct) {
            $bankId = $gateway->settle_to_account_id;
            $locked = $this->ledger->lock($gateway->id, $bankId);
            $gateway = $locked[$gateway->id];
            $bank = $locked[$bankId];
            $this->ledger->assertCovers($gateway, $gross, 'La liquidación');
            $t = Transfer::create(['code' => $this->sequences->next('treasury_transfer'), 'from_account_id' => $gateway->id, 'to_account_id' => $bank->id,
                'amount_from' => $gross, 'amount_to' => $net, 'fee_amount' => $fee, 'kind' => 'gateway_settlement', 'transfer_date' => $date, 'reference' => $batch]);
            $base = ['movement_date' => $date, 'source_type' => 'gateway', 'source_id' => $t->id, 'source_code' => $t->code, 'transfer_id' => $t->id, 'reference' => $batch];
            $this->ledger->post($gateway, $base + ['direction' => 'out', 'type' => 'gateway_settlement', 'amount' => $net, 'counterparty' => $bank->name,
                'description' => "Liquidación lote {$batch}: neto a {$bank->code}"]);
            if ($fee > 0) {
                $this->ledger->post($gateway, $base + ['direction' => 'out', 'type' => 'gateway_fee', 'amount' => $fee, 'category' => 'Comisión de pasarela',
                    'description' => "Comisión {$pct} % del lote {$batch}"]);
            }
            $this->ledger->post($bank, $base + ['direction' => 'in', 'type' => 'gateway_settlement', 'amount' => $net, 'counterparty' => $gateway->name,
                'description' => "Liquidación de pasarela {$gateway->code} · lote {$batch}"]);

            return $t;
        });
        $this->audit->log('Transferencia', $transfer->code, 'liquidar_pasarela', null,
            "{$gateway->code} lote {$batch}: bruto {$gateway->currency} {$gross} · comisión {$fee} · neto {$net}", actor: $actor);

        return $transfer;
    }

    /** TES-BR-05: la caja debe tener la sesión de hoy abierta. */
    public function assertSessionOpen(Account $account): CashSession
    {
        $session = CashSession::where('account_id', $account->id)->where('status', 'open')->first();
        if (! $session || $session->session_date->toDateString() !== $this->ledger->today()->toDateString()) {
            throw ApiException::unprocessable('TES-BR-05', "La caja {$account->code} no está aperturada hoy: apertúrela antes de registrar.");
        }

        return $session;
    }

    public function account(?string $id, string $field = 'account_id'): Account
    {
        $account = $id ? Account::where(Str::isUuid($id) ? 'id' : 'code', $id)->first() : null;
        if (! $account) {
            throw ApiException::invalid($field, 'Seleccione la cuenta.');
        }
        if ($account->status !== 'active') {
            throw ApiException::invalid($field, "La cuenta {$account->code} está inactiva.");
        }

        return $account;
    }

    /** TES-BR-01: sin fechas futuras. */
    public function date(mixed $value): string
    {
        $today = $this->ledger->today()->toDateString();
        if ($value === null || $value === '') {
            return $today;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
            throw ApiException::invalid('movement_date', 'Fecha inválida.');
        }
        if ((string) $value > $today) {
            throw ApiException::unprocessable('TES-BR-01', 'No se registran movimientos con fecha futura.');
        }

        return (string) $value;
    }

    public function amount(mixed $value, string $field = 'amount'): float
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            throw ApiException::invalid($field, 'El monto debe ser mayor a 0.');
        }

        return round((float) $value, 2);
    }

    public function str(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** TES-BR-09: los cobros en efectivo de la caja quedan como depositados (los más antiguos primero) hasta cubrir el depósito. */
    private function markDeposited(Account $from, Transfer $t, float $amount): void
    {
        $left = $amount;
        $pending = Movement::where('account_id', $from->id)->where('type', 'collection')->where('status', 'posted')->whereNull('deposited_transfer_id')
            ->orderBy('movement_date')->orderBy('created_at')->get();
        foreach ($pending as $m) {
            if ($left + 0.004 < (float) $m->amount) {
                break;
            }
            $m->forceFill(['deposited_transfer_id' => $t->id])->save();
            $left -= (float) $m->amount;
        }
    }

    private function accountAttributes(array $data, ?Account $current = null): array
    {
        $kind = (string) ($data['kind'] ?? '');
        if (! isset(Account::KINDS[$kind])) {
            throw ApiException::invalid('kind', 'Elija banco, caja chica o pasarela.');
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw ApiException::invalid('name', 'Indique el nombre de la cuenta.');
        }
        $currency = (string) ($data['currency'] ?? '');
        if (! in_array($currency, ['PEN', 'USD'], true)) {
            throw ApiException::invalid('currency', 'Moneda inválida.');
        }
        $sede = (Str::isUuid((string) ($data['sede_id'] ?? '')) ? Sede::find($data['sede_id']) : null) ?? throw ApiException::invalid('sede_id', 'Seleccione la sede.');
        $attrs = ['kind' => $kind, 'name' => mb_substr($name, 0, 120), 'currency' => $currency, 'sede_id' => $sede->id,
            'bank' => $this->str($data['bank'] ?? null, 40), 'account_number' => $this->str($data['account_number'] ?? null, 40),
            'cci' => $this->str($data['cci'] ?? null, 25), 'opening_balance' => round((float) ($data['opening_balance'] ?? 0), 2),
            'opening_rate' => $currency === 'USD' ? ((float) ($data['opening_rate'] ?? 0) ?: $this->ledger->rate('USD')) : null,
            'opening_date' => $data['opening_date'] ?? $this->ledger->today()->toDateString(),
            'collect_methods' => array_values(array_filter((array) ($data['collect_methods'] ?? $current?->collect_methods ?? []), 'is_string'))];
        if ($kind === 'bank' && (! $attrs['bank'] || ! $attrs['account_number'])) {
            throw ApiException::invalid('account_number', 'Indique el banco y el número de cuenta.');
        }
        if ($attrs['cci'] && ! preg_match('/^\d{20}$/', $attrs['cci'])) {
            throw ApiException::invalid('cci', 'El CCI tiene 20 dígitos.');
        }
        if ($kind === 'petty_cash') {
            $fund = (float) ($data['fund_amount'] ?? 0);
            $custodian = Str::isUuid((string) ($data['custodian_id'] ?? '')) ? User::find($data['custodian_id']) : null;
            if ($fund <= 0) {
                throw ApiException::invalid('fund_amount', 'Indique el fondo fijo de la caja.');
            }
            if (! $custodian) {
                throw ApiException::invalid('custodian_id', 'Seleccione el custodio de la caja.');
            }
            $attrs += ['fund_amount' => round($fund, 2), 'custodian_id' => $custodian->id];
        } else {
            $attrs += ['fund_amount' => null, 'custodian_id' => null];
        }
        if ($kind === 'gateway') {
            $settle = Account::where(Str::isUuid((string) ($data['settle_to_account_id'] ?? '')) ? 'id' : 'code', $data['settle_to_account_id'] ?? null)->where('kind', 'bank')->first();
            if (! $settle) {
                throw ApiException::invalid('settle_to_account_id', 'Seleccione la cuenta bancaria donde liquida la pasarela.');
            }
            $attrs['settle_to_account_id'] = $settle->id;
        } else {
            $attrs['settle_to_account_id'] = null;
        }
        if (! empty($data['bank_account_id'])) {
            $attrs['bank_account_id'] = Str::isUuid((string) $data['bank_account_id']) ? BankAccount::whereKey($data['bank_account_id'])->value('id') : null;
        }

        return $attrs;
    }

    private function duplicateOr(QueryException $e): \Throwable
    {
        return str_contains($e->getMessage(), 'fin_treasury_accounts_tenant_id_bank_account_number_unique')
            ? ApiException::conflict('DUPLICATE_ACCOUNT', 'Ya existe una cuenta con ese banco y número.') : $e;
    }
}
