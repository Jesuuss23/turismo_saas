<?php

namespace App\Services\Finance;

use App\Models\Finance\Commission;
use App\Models\Finance\Invoice;
use App\Models\Finance\Payable;
use App\Models\Finance\Payment;
use App\Models\Finance\Receivable;
use App\Services\Crm\CrmClock;

/** Paneles de Finanzas (§2.1) y Contabilidad (§2.2) con la misma fuente que el módulo (TC conservado, estado derivado; C19). */
class FinanceDashboards
{
    public function __construct(
        private readonly CrmClock $clock,
        private readonly FinanceIndicators $indicators,
        private readonly Profitability $profitability,
        private readonly PeriodCloseService $periods,
        private readonly FxRates $fx,
    ) {}

    public function finance(): array
    {
        $today = $this->clock->today();
        $month = $today->format('Y-m');
        $cash = Payment::whereIn('status', ['aplicado', 'confirmado'])->where('kind', '<>', 'reembolso')->whereRaw("to_char(paid_on, 'YYYY-MM') = ?", [$month])->get();
        $open = Receivable::whereIn('status', Receivable::OPEN)->get();
        $overdue = $open->where('status', 'vencida');
        $pending = Payment::where('status', 'pendiente')->with(['receivable:id,code', 'client:id,name'])->orderBy('paid_on')->get();
        $due = Payable::whereIn('status', Payable::PAYABLE)->whereDate('due_date', '<=', $today->copy()->addDays(7)->toDateString())
            ->with(['provider:id,name', 'reservation:id,code', 'activeInvoice'])->orderBy('due_date')->get();
        $overview = $this->profitability->overview();
        $last = collect($overview['monthly'])->last();
        $margins = collect($this->profitability->rows())->take(8)->map(fn ($r) => ['code' => $r['code'], 'title' => $r['title'], 'margin_pct' => $r['margin_pct'],
            'light' => $r['light'], 'label' => ['g' => 'En meta', 'a' => 'Vigilar', 'r' => 'Bajo mínimo'][$r['light']]])->values()->all();

        return [
            'alert' => ['pending_payments' => $pending->count()],
            'kpis' => [
                'cash_month' => Fin::money($cash->sum(fn (Payment $p) => $this->indicators->paymentPen($p))), 'cash_count' => $cash->count(),
                'receivables_open' => Fin::money($open->sum(fn (Receivable $r) => $this->indicators->receivablePen($r))), 'receivables_count' => $open->count(),
                'receivables_overdue' => Fin::money($overdue->sum(fn (Receivable $r) => $this->indicators->receivablePen($r))), 'overdue_count' => $overdue->count(),
                'pending_payments' => $pending->count(),
                'payables_7d' => Fin::money($due->sum(fn (Payable $p) => $this->fx->toPen($p->balance(), $p->currency, $p->fx_rate))), 'payables_7d_count' => $due->count(),
                'margin_month' => $last['margin_pct'] ?? 0, 'margin_target' => 28,
            ],
            'margins' => $margins,
            'aging' => $this->indicators->aging(5),
            'overdue' => $overdue->sortBy('due_date')->take(6)->map(fn (Receivable $r) => ['code' => $r->code, 'concept' => $r->concept, 'currency' => $r->currency,
                'balance' => $r->balance(), 'due_date' => $r->due_date->toDateString()])->values()->all(),
            'pending_payments' => $pending->take(6)->map(fn (Payment $p) => ['code' => $p->code, 'client' => $p->client?->name, 'method' => $p->method,
                'currency' => $p->currency, 'amount' => $p->amount, 'paid_on' => $p->paid_on->toDateString(), 'receivable' => $p->receivable?->code])->values()->all(),
            'payables_due' => $due->take(8)->map(fn (Payable $p) => ['code' => $p->code, 'provider' => $p->provider?->name, 'reservation' => $p->reservation?->code,
                'currency' => $p->currency, 'balance' => $p->balance(), 'due_date' => $p->due_date->toDateString(), 'status' => $p->status,
                'without_invoice' => $p->activeInvoice === null])->values()->all(),
            'commissions' => Commission::where('status', 'calculada')->with(['reservation:id,code', 'user:id,name', 'agency:id,name'])->orderBy('created_at')->take(6)->get()
                ->map(fn (Commission $c) => ['code' => $c->code, 'beneficiary' => $c->beneficiaryName(), 'reservation' => $c->reservation?->code,
                    'currency' => $c->currency, 'amount' => $c->amount])->all(),
            'sales_vs_cost' => array_map(fn ($m) => ['month' => $m['month'], 'income' => $m['income'], 'cost' => $m['cost']], $overview['monthly']),
            'cash_by_method' => $cash->groupBy('method')->map(fn ($set, $method) => ['method' => $method,
                'amount' => Fin::money($set->sum(fn (Payment $p) => $this->indicators->paymentPen($p)))])->sortByDesc('amount')->values()->all(),
        ];
    }

    public function accounting(): array
    {
        $today = $this->clock->today();
        $month = $today->format('Y-m');
        $monthInvoices = Invoice::where('period', $month)->where('doc_type', '<>', Invoice::CREDIT_NOTE)->whereIn('status', Invoice::COUNTED)->get();
        $flagged = Invoice::whereIn('status', ['observado', 'rechazado'])->orderByDesc('issue_date')->get();
        $without = $this->indicators->paymentsWithoutInvoice();
        $open = Receivable::whereIn('status', Receivable::OPEN)->with(['reservation:id,code', 'client:id,name'])->orderBy('due_date')->get();

        return [
            'alerts' => $flagged->map(fn (Invoice $i) => ['number' => $i->number, 'status' => $i->status, 'status_label' => Invoice::STATUS_LABELS[$i->status],
                'message' => $i->ose_response_message])->all(),
            'kpis' => [
                'invoices_month' => Fin::money($monthInvoices->sum(fn (Invoice $i) => $this->indicators->invoicePen($i))), 'invoices_month_count' => $monthInvoices->count(),
                'flagged' => $flagged->count(), 'without_invoice' => $without->count(), 'receivables_open' => $open->count(),
            ],
            'invoices' => Invoice::orderByDesc('issue_date')->orderByDesc('correlative')->take(8)->get()->map(fn (Invoice $i) => ['number' => $i->number,
                'type' => Invoice::TYPE_LABELS[$i->doc_type], 'customer' => $i->customer_name, 'currency' => $i->currency, 'total' => $i->total_amount,
                'issue_date' => $i->issue_date->toDateString(), 'status' => $i->status, 'status_label' => Invoice::STATUS_LABELS[$i->status]])->all(),
            'without_invoice' => $without->take(8)->map(fn (Payment $p) => ['code' => $p->code, 'reservation' => $p->reservation?->code, 'client' => $p->client?->name,
                'currency' => $p->currency, 'amount' => $p->amount, 'paid_on' => $p->paid_on->toDateString()])->all(),
            'receivables' => $open->take(8)->map(fn (Receivable $r) => ['code' => $r->code, 'reservation' => $r->reservation?->code, 'client' => $r->client?->name,
                'currency' => $r->currency, 'balance' => $r->balance(), 'due_date' => $r->due_date->toDateString(), 'status' => $r->status,
                'status_label' => Receivable::STATUS_LABELS[$r->status]])->all(),
            'period' => $this->periods->present($this->periods->find($month)),
        ];
    }
}
