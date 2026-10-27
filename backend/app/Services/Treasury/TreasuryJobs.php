<?php

namespace App\Services\Treasury;

use App\Models\Iam\User;
use App\Models\Treasury\Account;
use App\Models\Treasury\CashSession;
use App\Services\Notify;

/** Jobs de tesorería (spec 12 §8): JOB-12 depósito de efectivo (TES-BR-09), JOB-13 cajas sin aperturar (TES-BR-05), JOB-14 flujo proyectado. */
class TreasuryJobs
{
    public function __construct(
        private readonly TreasuryPresenter $present,
        private readonly TreasuryLedger $ledger,
        private readonly CashFlowService $flow,
        private readonly Notify $notify,
    ) {}

    public function cashDepositAlerts(): string
    {
        $rows = $this->present->undeposited();
        if ($rows->isEmpty()) {
            return 'Sin cobros en efectivo pendientes de depósito';
        }
        $text = $rows->map(fn ($m) => "{$m->source_code} S/ {$m->amount} ({$m->account?->name})")->implode(', ');
        foreach ($this->financeUsers() as $u) {
            $this->notify->push('caja.deposito_pendiente', 'portal', $u, "{$rows->count()} cobro(s) en efectivo sin depositar",
                "Más de {$this->ledger->settings()->cash_deposit_hours} h en caja: {$text}.", ['link' => '/finanzas/tesoreria']);
        }

        return "{$rows->count()} cobro(s) en efectivo sin depositar: alerta enviada a Finanzas";
    }

    public function openSessionCheck(): string
    {
        $today = $this->ledger->today()->toDateString();
        $missing = Account::where('kind', 'petty_cash')->where('status', 'active')->orderBy('code')->get()
            ->reject(fn (Account $a) => CashSession::where('account_id', $a->id)->where('status', 'open')->whereDate('session_date', $today)->exists());
        if ($missing->isEmpty()) {
            return 'Todas las cajas chicas están aperturadas';
        }
        foreach ($this->financeUsers() as $u) {
            $this->notify->push('caja.sin_aperturar', 'portal', $u, "{$missing->count()} caja(s) sin aperturar", $missing->pluck('name')->implode(', ').'.',
                ['link' => '/finanzas/tesoreria/cajas']);
        }

        return "{$missing->count()} caja(s) sin aperturar: ".$missing->pluck('name')->implode(', ');
    }

    public function cashFlowSnapshot(): string
    {
        $this->flow->forget();
        $flow = $this->flow->cached();

        return sprintf('Flujo proyectado actualizado: saldo a 30 días S/ %s', number_format($flow['projected_30'], 2));
    }

    private function financeUsers()
    {
        return User::permission('finance.payments.register')->where('status', User::ACTIVE)->get();
    }
}
