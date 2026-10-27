<?php

namespace App\Services\Treasury;

use App\Models\Iam\User;
use App\Models\Treasury\Account;
use App\Models\Treasury\CashCount;
use App\Models\Treasury\CashSession;
use App\Models\Treasury\ExpenseReceipt;
use App\Models\Treasury\FxRevaluation;
use App\Models\Treasury\Movement;
use App\Models\Treasury\Replenishment;
use App\Models\Treasury\Transfer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Vistas de tesorería (spec 12 §2): resumen, libro de movimientos, cajas chicas, cuenta, flujo y diferencia cambiaria. */
class TreasuryPresenter
{
    public const TYPE_LABELS = Movement::TYPES;

    public function __construct(
        private readonly TreasuryLedger $ledger,
        private readonly PettyCashService $petty,
        private readonly CashFlowService $flow,
    ) {}

    /** Permisos de la vista (los botones se deshabilitan con tooltip según §7). */
    public function can(User $user): array
    {
        return [
            'register' => $user->hasPermissionTo('finance.payments.register'),
            'approve' => $user->hasPermissionTo('finance.profitability') && $user->hasPermissionTo('approvals.view'),
            'validate' => $user->hasPermissionTo('finance.invoicing'),
            'fx' => $user->hasPermissionTo('finance.invoicing'),
            'accounts' => $user->hasPermissionTo('admin.settings.manage'),
        ];
    }

    public function summary(User $user): array
    {
        $balances = $this->ledger->balances();
        $usd = $this->ledger->rate('USD');
        $accounts = $this->accounts($balances, $usd);
        $active = collect($accounts)->where('status', 'active');
        $pen = fn ($rows) => round($rows->sum('balance_pen'), 2);
        $flow = $this->flow->cached();

        return [
            'as_of' => $this->ledger->today()->toDateString(), 'fx_usd' => $usd, 'can' => $this->can($user),
            'kpis' => [
                'consolidated_pen' => $pen($active),
                'banks_pen' => round($active->where('kind', 'bank')->where('currency', 'PEN')->sum('balance'), 2),
                'banks_usd' => round($active->where('kind', 'bank')->where('currency', 'USD')->sum('balance'), 2),
                'cash_gateway_pen' => $pen($active->whereIn('kind', ['petty_cash', 'gateway'])),
                'projected_30' => $flow['projected_30'],
            ],
            'accounts' => $accounts,
            'composition' => $active->groupBy('kind')->map(fn ($rows, $kind) => ['label' => Account::KINDS[$kind].' '.$rows->pluck('currency')->unique()->implode('/'),
                'value' => $pen($rows)])->values()->all(),
            'alerts' => $this->alerts($balances),
            'series' => $this->series(28, $usd),
            'latest' => Movement::with('account:id,code,name,currency')->orderByDesc('movement_date')->orderByDesc('created_at')->limit(8)->get()
                ->map(fn ($m) => $this->movement($m))->all(),
        ];
    }

    public function accounts(?array $balances = null, ?float $usd = null): array
    {
        $balances ??= $this->ledger->balances();
        $usd ??= $this->ledger->rate('USD');
        $today = $this->ledger->today()->toDateString();
        $sessions = CashSession::where('status', 'open')->get()->keyBy('account_id');

        return Account::with(['sede:id,name', 'custodian:id,name', 'settleTo:id,code,name'])->orderBy('code')->get()->map(function (Account $a) use ($balances, $usd, $sessions, $today) {
            $balance = $balances[$a->id] ?? (float) $a->opening_balance;
            $session = $sessions[$a->id] ?? null;

            return [
                'id' => $a->id, 'code' => $a->code, 'kind' => $a->kind, 'kind_label' => Account::KINDS[$a->kind], 'bank' => $a->bank, 'name' => $a->name,
                'account_number' => $a->account_number, 'cci' => $a->cci, 'currency' => $a->currency, 'sede' => $a->sede?->name, 'sede_id' => $a->sede_id,
                'balance' => $balance, 'balance_pen' => round($balance * ($a->currency === 'USD' ? $usd : 1), 2),
                'fund_amount' => $a->fund_amount, 'fund_pct' => $a->fund_amount ? (int) round($balance / (float) $a->fund_amount * 100) : null,
                'custodian' => $a->custodian?->name, 'custodian_id' => $a->custodian_id, 'settle_to' => $a->settleTo?->only(['id', 'code', 'name']),
                'collect_methods' => $a->collect_methods, 'status' => $a->status, 'lock_version' => $a->lock_version,
                'opening_balance' => $a->opening_balance, 'opening_date' => $a->opening_date?->toDateString(), 'opening_rate' => $a->opening_rate,
                'session' => $session ? ['code' => $session->code, 'date' => $session->session_date->toDateString(), 'today' => $session->session_date->toDateString() === $today] : null,
            ];
        })->all();
    }

    /** Alertas TES-BR del resumen. */
    public function alerts(?array $balances = null): array
    {
        $balances ??= $this->ledger->balances();
        $settings = $this->ledger->settings();
        $today = $this->ledger->today()->toDateString();
        $out = [];
        foreach (Account::where('status', 'active')->where('kind', 'petty_cash')->orderBy('code')->get() as $a) {
            $balance = $balances[$a->id] ?? 0;
            if ($balance < (float) $a->fund_amount * $settings->replenish_threshold_pct / 100) {
                $out[] = ['rule' => 'TES-BR-08', 'tone' => 'warning', 'text' => sprintf('%s: saldo S/ %s bajo el %d %% del fondo (S/ %s). Solicite la reposición.',
                    $a->name, number_format($balance, 2), $settings->replenish_threshold_pct, number_format((float) $a->fund_amount, 2)), 'link' => '/finanzas/tesoreria/cajas'];
            }
            $session = CashSession::where('account_id', $a->id)->where('status', 'open')->first();
            if ($session && $session->session_date->toDateString() < $today) {
                $out[] = ['rule' => 'TES-BR-06', 'tone' => 'danger', 'text' => "{$a->name}: la sesión {$session->code} del ".$session->session_date->format('d/m').' sigue abierta. Ciérrela con un arqueo.',
                    'link' => '/finanzas/tesoreria/cajas'];
            } elseif (! $session) {
                $out[] = ['rule' => 'TES-BR-05', 'tone' => 'info', 'text' => "{$a->name}: sin aperturar hoy.", 'link' => '/finanzas/tesoreria/cajas'];
            }
        }
        foreach ($this->undeposited() as $m) {
            $out[] = ['rule' => 'TES-BR-09', 'tone' => 'warning', 'text' => sprintf('Cobro en efectivo %s (S/ %s) en %s sin depositar hace más de %d h.',
                $m->source_code ?? $m->code, number_format((float) $m->amount, 2), $m->account?->name, $settings->cash_deposit_hours), 'link' => "/finanzas/tesoreria/cuentas/{$m->account?->code}"];
        }
        foreach (CashCount::where('status', 'pending_approval')->with('account:id,name')->get() as $c) {
            $out[] = ['rule' => 'TES-BR-13', 'tone' => 'warning', 'text' => "Arqueo {$c->code} ({$c->account?->name}) con diferencia de S/ {$c->difference}: pendiente de aprobación.",
                'link' => '/finanzas/tesoreria/cajas'];
        }
        foreach (Replenishment::whereIn('status', ['requested', 'approved'])->with('account:id,name')->get() as $r) {
            $out[] = ['rule' => 'TES-BR-15', 'tone' => 'info', 'text' => "Reposición {$r->code} de {$r->account?->name} (S/ {$r->amount}): ".mb_strtolower(Replenishment::STATUS_LABELS[$r->status]).'.',
                'link' => '/finanzas/tesoreria/cajas'];
        }

        return $out;
    }

    /** TES-BR-09: cobros en efectivo en caja sin depositar pasadas N horas. */
    public function undeposited(): Collection
    {
        $hours = $this->ledger->settings()->cash_deposit_hours;

        return Movement::where('type', 'collection')->where('status', 'posted')->whereNull('deposited_transfer_id')
            ->whereHas('account', fn ($q) => $q->where('kind', 'petty_cash'))->where('created_at', '<', now()->subHours($hours))->with('account:id,code,name')->get();
    }

    /** Saldo consolidado (S/) al cierre de cada uno de los últimos `$days` días. */
    public function series(int $days, float $usd): array
    {
        $from = $this->ledger->today()->subDays($days - 1);
        $accounts = Account::get(['id', 'currency'])->keyBy('id');
        $start = $this->ledger->balances($from->copy()->subDay()->toDateString());
        $running = collect($start)->sum(fn ($b, $id) => $b * (($accounts[$id]->currency ?? 'PEN') === 'USD' ? $usd : 1));
        $deltas = Movement::where('status', 'posted')->whereDate('movement_date', '>=', $from->toDateString())->get(['account_id', 'direction', 'amount', 'movement_date'])
            ->groupBy(fn ($m) => $m->movement_date->toDateString())
            ->map(fn ($rows) => $rows->sum(fn ($m) => $m->signed() * (($accounts[$m->account_id]->currency ?? 'PEN') === 'USD' ? $usd : 1)));
        $out = [];
        for ($d = $from->copy(); $d->lte($this->ledger->today()); $d->addDay()) {
            $running += $deltas[$d->toDateString()] ?? 0;
            $out[] = ['label' => $d->format('d/m'), 'value' => round($running, 2)];
        }

        return $out;
    }

    public function movements(array $filters): array
    {
        $q = Movement::with(['account:id,code,name,currency,kind', 'receipt', 'creator:id,name'])
            ->when($filters['account'] ?? null, fn ($q, $v) => $q->whereHas('account', fn ($a) => $a->where(Str::isUuid($v) ? 'id' : 'code', $v)))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['direction'] ?? null, fn ($q, $v) => $q->where('direction', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('movement_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('movement_date', '<=', $v))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('code', 'ilike', "%{$v}%")->orWhere('description', 'ilike', "%{$v}%")
                ->orWhere('source_code', 'ilike', "%{$v}%")->orWhere('reference', 'ilike', "%{$v}%")))
            ->orderByDesc('movement_date')->orderByDesc('created_at');
        $total = (clone $q)->count();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $rows = $q->forPage($page, 50)->get();

        return ['data' => $rows->map(fn ($m) => $this->movement($m))->all(), 'meta' => ['total' => $total, 'page' => $page, 'per_page' => 50]];
    }

    public function movement(Movement $m, bool $detail = false): array
    {
        $row = [
            'id' => $m->id, 'code' => $m->code, 'date' => $m->movement_date->toDateString(), 'direction' => $m->direction, 'type' => $m->type,
            'type_label' => Movement::TYPES[$m->type] ?? $m->type, 'amount' => $m->amount, 'currency' => $m->currency, 'fx_rate' => $m->fx_rate,
            'orig_amount' => $m->orig_amount, 'orig_currency' => $m->orig_currency, 'orig_fx_rate' => $m->orig_fx_rate,
            'account' => $m->account ? ['code' => $m->account->code, 'name' => $m->account->name] : null,
            'description' => $m->description, 'reference' => $m->reference, 'counterparty' => $m->counterparty, 'category' => $m->category,
            'source' => $this->source($m), 'status' => $m->status, 'synced' => $m->isSynced(),
            'can_void' => $this->voidable($m), 'void_reason' => $m->void_reason,
            'receipt' => $m->receipt ? ['type' => $m->receipt->receipt_type, 'type_label' => ExpenseReceipt::TYPES[$m->receipt->receipt_type], 'series_number' => $m->receipt->series_number,
                'issuer_ruc' => $m->receipt->issuer_ruc, 'supplier_name' => $m->receipt->supplier_name, 'file_name' => $m->receipt->file_name] : null,
            'replenishment' => $m->replenishment_id ? Replenishment::whereKey($m->replenishment_id)->value('code') : null,
            'deposited' => $m->deposited_transfer_id ? Transfer::whereKey($m->deposited_transfer_id)->value('code') : null,
        ];
        if ($detail) {
            $row += [
                'reservation' => $m->reservation_id ? $m->reservation()->value('code') : null,
                'created_by' => $m->creator?->name ?? 'Sistema', 'created_at' => $m->created_at?->toIso8601String(),
                'voided_by' => $m->voider?->name, 'voided_at' => $m->voided_at?->toIso8601String(),
                'partidas' => $m->transfer_id ? Movement::where('transfer_id', $m->transfer_id)->with('account:id,code,name')->orderBy('direction')->get()
                    ->map(fn ($p) => ['code' => $p->code, 'account' => $p->account?->code, 'direction' => $p->direction, 'type_label' => Movement::TYPES[$p->type],
                        'amount' => $p->amount, 'currency' => $p->currency, 'status' => $p->status])->all() : [],
            ];
        }

        return $row;
    }

    public function voidable(Movement $m): bool
    {
        if ($m->status !== 'posted' || $m->isSynced()) {
            return false;
        }
        if ($m->type === 'petty_expense') {
            return $m->replenishment_id === null;
        }

        return in_array($m->source_type, ['manual', 'transfer', 'gateway'], true) || ($m->source_type === 'replenishment' && $m->transfer_id);
    }

    /** Origen enlazado (PAG/CXP/TRF/ARQ/REP). */
    public function source(Movement $m): array
    {
        return match ($m->source_type) {
            'payment', 'payment_reversal' => ['label' => $m->source_code, 'kind' => 'Pago', 'href' => "/finanzas/pagos?code={$m->source_code}"],
            'payout' => ['label' => $m->source_code, 'kind' => 'CxP', 'href' => $m->source_code ? "/finanzas/cuentas-por-pagar/{$m->source_code}" : null],
            'transfer', 'gateway' => ['label' => $m->source_code, 'kind' => 'Transferencia', 'href' => null],
            'cash_count' => ['label' => $m->source_code, 'kind' => 'Arqueo', 'href' => '/finanzas/tesoreria/cajas'],
            'replenishment' => ['label' => $m->source_code, 'kind' => 'Reposición', 'href' => '/finanzas/tesoreria/cajas'],
            'expense' => ['label' => $m->receipt?->series_number ?? ExpenseReceipt::TYPES[$m->receipt?->receipt_type ?? 'ticket'] ?? 'Comprobante', 'kind' => 'Gasto', 'href' => null],
            default => ['label' => 'Manual', 'kind' => 'Manual', 'href' => null],
        };
    }

    /** Libro de una cuenta con saldo acumulado. */
    public function ledger(Account $account, array $filters): array
    {
        $from = $filters['from'] ?? null;
        $opening = $from ? $this->ledger->balance($account, Carbon::parse($from)->subDay()->toDateString()) : (float) $account->opening_balance;
        $rows = Movement::where('account_id', $account->id)->with(['receipt', 'account:id,code,name,currency,kind'])
            ->when($from, fn ($q, $v) => $q->whereDate('movement_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('movement_date', '<=', $v))
            ->orderBy('movement_date')->orderBy('created_at')->get();
        $running = $opening;
        $out = $rows->map(function ($m) use (&$running) {
            if ($m->status === 'posted') {
                $running = round($running + $m->signed(), 2);
            }

            return $this->movement($m) + ['running' => $running];
        })->reverse()->values();
        $a = collect($this->accounts())->firstWhere('id', $account->id);

        return ['account' => $a, 'opening' => round($opening, 2), 'from' => $from, 'movements' => $out->all(),
            'counts' => $account->isPetty() ? CashCount::where('account_id', $account->id)->orderByDesc('counted_at')->limit(10)->get()->map(fn ($c) => $this->count($c))->all() : [],
            'pending_expenses' => $account->isPetty() ? round((float) $this->petty->pendingExpenses($account)->sum('amount'), 2) : null,
            'gateway_fee_pct' => $account->kind === 'gateway' ? (float) $this->ledger->settings()->gateway_fee_pct : null];
    }

    /** Cajas chicas: tarjeta por caja, arqueos, reposiciones y sesiones. */
    public function pettyCash(User $user): array
    {
        $balances = $this->ledger->balances();
        $settings = $this->ledger->settings();
        $today = $this->ledger->today()->toDateString();
        $cards = Account::where('kind', 'petty_cash')->with(['sede:id,name', 'custodian:id,name'])->orderBy('code')->get()->map(function (Account $a) use ($balances, $today, $settings) {
            $session = CashSession::where('account_id', $a->id)->orderByDesc('session_date')->first();
            $pending = $this->petty->pendingExpenses($a);
            $rep = Replenishment::where('account_id', $a->id)->whereIn('status', ['requested', 'approved'])->first();
            $balance = $balances[$a->id] ?? 0;

            return ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'sede' => $a->sede?->name, 'custodian' => $a->custodian?->name, 'status' => $a->status,
                'balance' => $balance, 'fund_amount' => $a->fund_amount, 'fund_pct' => (int) round($balance / max(0.01, (float) $a->fund_amount) * 100),
                'below_threshold' => $balance < (float) $a->fund_amount * $settings->replenish_threshold_pct / 100,
                'session' => $session ? ['code' => $session->code, 'date' => $session->session_date->toDateString(), 'status' => $session->status,
                    'today' => $session->session_date->toDateString() === $today, 'opening_amount' => $session->opening_amount] : null,
                'open_today' => $session && $session->status === 'open' && $session->session_date->toDateString() === $today,
                'stale_session' => $session && $session->status === 'open' && $session->session_date->toDateString() < $today,
                'pending_count' => $pending->count(), 'pending_amount' => round((float) $pending->sum('amount'), 2),
                'replenishment' => $rep ? ['id' => $rep->id, 'code' => $rep->code, 'status' => $rep->status, 'status_label' => Replenishment::STATUS_LABELS[$rep->status], 'amount' => $rep->amount, 'lock_version' => $rep->lock_version] : null,
                'expenses' => $pending->map(fn ($m) => ['code' => $m->code, 'date' => $m->movement_date->toDateString(), 'amount' => $m->amount, 'description' => $m->description,
                    'counterparty' => $m->counterparty])->all()];
        });

        return [
            'can' => $this->can($user), 'user_id' => $user->id,
            'settings' => ['petty_tolerance' => $settings->petty_tolerance, 'max_petty_expense' => $settings->max_petty_expense, 'sworn_statement_max' => $settings->sworn_statement_max,
                'replenish_threshold_pct' => $settings->replenish_threshold_pct],
            'denominations' => CashCount::DENOMINATIONS, 'receipt_types' => ExpenseReceipt::TYPES,
            'banks' => Account::where('kind', 'bank')->where('status', 'active')->where('currency', 'PEN')->orderBy('code')->get(['id', 'code', 'name'])->all(),
            'witnesses' => User::where('status', User::ACTIVE)->permission('finance.treasury')->orderBy('name')->get(['id', 'name'])->all(),
            'cards' => $cards->all(),
            'counts' => CashCount::with(['account:id,code,name', 'signer:id,name', 'approver:id,name', 'validator:id,name'])->orderByDesc('counted_at')->limit(30)->get()
                ->map(fn ($c) => $this->count($c))->all(),
            'replenishments' => Replenishment::with(['account:id,code,name', 'requester:id,name', 'approver:id,name', 'payer:id,name', 'events.user:id,name'])
                ->orderByDesc('requested_at')->limit(20)->get()->map(fn ($r) => $this->replenishment($r))->all(),
            'sessions' => CashSession::with(['account:id,code,name', 'opener:id,name', 'closer:id,name', 'closingCount:id,code'])->orderByDesc('session_date')->orderBy('account_id')
                ->limit(20)->get()->map(fn ($s) => ['code' => $s->code, 'account' => $s->account?->name, 'date' => $s->session_date->toDateString(), 'status' => $s->status,
                    'opened_by' => $s->opener?->name, 'opened_at' => $s->opened_at?->toIso8601String(), 'opening_amount' => $s->opening_amount,
                    'closed_by' => $s->closer?->name, 'closed_at' => $s->closed_at?->toIso8601String(), 'count' => $s->closingCount?->code])->all(),
        ];
    }

    public function count(CashCount $c): array
    {
        return ['id' => $c->id, 'code' => $c->code, 'account' => $c->account?->name, 'kind' => $c->kind, 'kind_label' => $c->kind === 'closing' ? 'Cierre' : 'Sorpresa',
            'counted_at' => $c->counted_at?->toIso8601String(), 'denominations' => $c->denominations, 'counted_amount' => $c->counted_amount,
            'expected_amount' => $c->expected_amount, 'difference' => $c->difference, 'result' => $c->result, 'result_label' => CashCount::RESULTS[$c->result],
            'note' => $c->note, 'status' => $c->status, 'status_label' => CashCount::STATUS_LABELS[$c->status], 'signed_by' => $c->signer?->name, 'signed_by_id' => $c->signed_by,
            'approved_by' => $c->approver?->name, 'approve_note' => $c->approve_note, 'validated_by' => $c->validator?->name, 'lock_version' => $c->lock_version];
    }

    public function replenishment(Replenishment $r): array
    {
        return ['id' => $r->id, 'code' => $r->code, 'account' => $r->account?->name, 'account_code' => $r->account?->code, 'amount' => $r->amount, 'status' => $r->status,
            'status_label' => Replenishment::STATUS_LABELS[$r->status], 'requested_by' => $r->requester?->name, 'requested_by_id' => $r->requested_by,
            'requested_at' => $r->requested_at?->toIso8601String(), 'approved_by' => $r->approver?->name, 'paid_by' => $r->payer?->name, 'paid_at' => $r->paid_at?->toIso8601String(),
            'reject_reason' => $r->reject_reason, 'note' => $r->note, 'lock_version' => $r->lock_version, 'expenses' => Movement::where('replenishment_id', $r->id)->count(),
            'events' => $r->events->map(fn ($e) => ['status' => $e->status, 'message' => $e->message, 'user' => $e->user?->name, 'at' => $e->created_at?->toIso8601String()])->all()];
    }

    public function fxHistory(): array
    {
        return FxRevaluation::with('account:id,code,name')->orderByDesc('period')->orderBy('code')->get()
            ->map(fn ($r) => ['code' => $r->code, 'account' => $r->account?->name, 'period' => $r->period, 'date' => $r->revaluation_date->toDateString(),
                'balance_usd' => $r->balance_usd, 'book_rate' => $r->book_rate, 'close_rate' => $r->close_rate, 'diff_pen' => $r->diff_pen])->all();
    }
}
