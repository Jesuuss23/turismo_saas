<?php

namespace App\Http\Controllers\Api\Finance;

use App\Models\Bookings\BookedService;
use App\Models\Catalog\Provider;
use App\Models\Finance\BankAccount;
use App\Models\Finance\Payable;
use App\Models\Finance\Payout;
use App\Models\Ops\Voucher;
use App\Models\Supplier\SupplierInvoice;
use App\Services\Crm\CrmClock;
use App\Services\Finance\Fin;
use App\Services\Finance\FxRates;
use App\Services\Finance\PayableManager;
use App\Services\Supplier\SupplierPortal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** §6.3 Cuentas por pagar y costos (PROC-044/045/046). Reemplaza el listado `fin/payables` de la extranet (spec 06). */
class PayableController extends FinanceController
{
    public function index(Request $request): JsonResponse
    {
        $rows = Payable::with(['provider:id,name,ref,status', 'reservation:id,code', 'service:id,code,status', 'activeInvoice', 'invoices'])
            ->when($request->input('filter.status'), fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->where('status', '<>', Payable::CANCELLED))
            ->when($request->input('filter.kind'), fn ($q, $k) => $q->where('kind', $k))
            ->when($request->input('filter.provider'), fn ($q, $p) => $q->whereHas('provider', fn ($w) => $w->where('ref', $p)))
            ->when($request->input('filter.reservation'), fn ($q, $c) => $q->whereHas('reservation', fn ($w) => $w->where('code', $c)))
            ->when($request->input('filter.invoice') === 'registrada', fn ($q) => $q->whereHas('invoices', fn ($w) => $w->where('status', 'registrada')))
            ->when($request->input('filter.approval') === 'pendiente', fn ($q) => $q->whereNull('approved_at')->whereIn('status', Payable::PAYABLE))
            ->orderBy('due_date')->limit(500)->get();
        // Prueba de prestación (spec 06 §4.4): el voucher del servicio y la factura que sube el proveedor desde la extranet.
        $vouchers = Voucher::whereIn('reservation_service_id', $rows->pluck('reservation_service_id')->filter())->latest('issued_at')->get()->keyBy('reservation_service_id');

        return response()->json(['data' => $rows->map(function (Payable $p) use ($vouchers) {
            $v = $vouchers[$p->reservation_service_id] ?? null;

            return $this->present->payable($p) + [
                'service_status' => $p->service ? (BookedService::STATUS_LABELS[$p->service->status] ?? $p->service->status) : null,
                'voucher' => $v ? ['code' => $v->code, 'status' => $v->status, 'status_label' => Voucher::STATUS_LABELS[$v->status], 'used_at' => $v->used_at?->toIso8601String(),
                    'used_via' => $v->used_via] : null,
                'invoices' => $p->invoices->map(fn (SupplierInvoice $i) => SupplierPortal::invoiceRow($i))->all(),
            ];
        })->all(), 'meta' => [
            'labels' => Payable::STATUS_LABELS, 'cost_types' => Payable::COST_TYPES, 'invoices_to_review' => SupplierInvoice::where('status', 'registrada')->count(),
            'providers' => Provider::orderBy('name')->get(['id', 'ref', 'name', 'status'])->map(fn ($p) => ['id' => $p->id, 'ref' => $p->ref, 'name' => $p->name, 'status' => $p->status])->all(),
        ]]);
    }

    public function summary(CrmClock $clock, FxRates $fx): JsonResponse
    {
        $today = $clock->today();
        $open = Payable::whereIn('status', Payable::PAYABLE)->get();
        $pen = fn ($set) => Fin::money($set->sum(fn (Payable $p) => $fx->toPen($p->balance(), $p->currency, $p->fx_rate)));
        $paidMonth = Payout::whereRaw("to_char(paid_on, 'YYYY-MM') = ?", [$today->format('Y-m')])->with('payable:id,currency,fx_rate')->get();
        $scheduled = $open->filter(fn (Payable $p) => $p->scheduled_date && $p->scheduled_date->toDateString() <= $today->copy()->addDays(7)->toDateString());

        return response()->json(['data' => [
            'open' => $pen($open), 'open_count' => $open->count(), 'overdue' => $pen($open->where('status', 'vencida')), 'overdue_count' => $open->where('status', 'vencida')->count(),
            'to_approve' => $open->whereNull('approved_at')->count(),
            'paid_month' => Fin::money($paidMonth->sum(fn (Payout $o) => $fx->toPen($o->amount, $o->payable->currency, $o->fx_rate ?? $o->payable->fx_rate))),
            'scheduled_7d' => $pen($scheduled), 'scheduled_7d_count' => $scheduled->count(),
        ]]);
    }

    public function byProvider(CrmClock $clock, FxRates $fx): JsonResponse
    {
        $today = $clock->today()->toDateString();
        $rows = Payable::where('status', '<>', Payable::CANCELLED)->with('provider:id,name,ref,ruc')->get()->groupBy('provider_id')
            ->map(function ($set) use ($fx, $today) {
                $pen = fn ($items, string $what) => Fin::money($items->sum(fn (Payable $p) => $fx->toPen($what === 'balance' ? $p->balance() : $p->{$what}, $p->currency, $p->fx_rate)));
                $open = $set->whereIn('status', Payable::PAYABLE);
                $next = $open->sortBy('due_date')->first();

                return ['provider' => $set->first()->provider?->name, 'provider_ref' => $set->first()->provider?->ref, 'ruc' => $set->first()->provider?->ruc,
                    'documents' => $set->count(), 'total' => $pen($set, 'amount'), 'paid' => $pen($set, 'paid_amount'), 'balance' => $pen($open, 'balance'),
                    'overdue' => $pen($open->filter(fn (Payable $p) => $p->due_date->toDateString() < $today), 'balance'),
                    'next_due' => $next?->due_date->toDateString()];
            })->sortByDesc(fn ($r) => (float) $r['balance'])->values()->all();

        return response()->json(['data' => $rows]);
    }

    public function show(PayableManager $payables, string $code): JsonResponse
    {
        $p = $this->byCode(Payable::class, $code);
        $p->load(['provider', 'reservation', 'service', 'payouts', 'invoices', 'activeInvoice']);

        return response()->json(['data' => $this->present->payable($p) + [
            'provider_email' => $p->provider?->email, 'provider_ruc' => $p->provider?->ruc, 'warnings' => $payables->warnings($p),
            'scheduled_account' => $p->scheduled_bank_account_id ? BankAccount::find($p->scheduled_bank_account_id)?->label : null,
            'payouts' => $p->payouts->map(fn (Payout $o) => $this->present->payout($o))->all(),
            'invoices' => $p->invoices->map(fn ($i) => ['id' => $i->id, 'number' => $i->number, 'issue_date' => $i->issue_date?->toDateString(), 'amount' => (string) $i->amount,
                'status' => $i->status, 'uploaded_at' => $i->uploaded_at?->toIso8601String()])->all(),
            'audit' => $this->present->audit(['Cuenta por pagar' => [$p->code], 'Costo' => [$p->code]]),
        ]]);
    }

    public function approve(Request $request, PayableManager $payables, string $code): JsonResponse
    {
        $result = $payables->approve($this->byCode(Payable::class, $code), $this->actor($request));

        return response()->json(['data' => $this->present->payable($result['payable']), 'meta' => ['warnings' => $result['warnings']]]);
    }

    public function schedule(Request $request, PayableManager $payables, string $code): JsonResponse
    {
        return response()->json(['data' => $this->present->payable($payables->schedule($this->byCode(Payable::class, $code), $request->all(), $this->actor($request)))]);
    }

    public function payout(Request $request, PayableManager $payables, string $code): JsonResponse
    {
        $payout = $payables->payout($this->byCode(Payable::class, $code), $request->all(), $this->actor($request));

        return response()->json(['data' => $this->present->payout($payout) + ['payable' => $this->present->payable(Payable::find($payout->payable_id))]], 201);
    }

    public function previewCost(Request $request, PayableManager $payables): JsonResponse
    {
        $request->validate(['reservation_id' => 'required|string', 'amount' => 'required|numeric|gte:0', 'currency' => 'nullable|in:PEN,USD']);
        $reservation = $this->reservation($request->input('reservation_id'));

        return response()->json(['data' => $payables->previewCost($reservation, (float) $request->input('amount'), $request->input('currency', $reservation->currency),
            $request->filled('fx_rate') ? (float) $request->input('fx_rate') : null)]);
    }

    public function storeCost(Request $request, PayableManager $payables): JsonResponse
    {
        $p = $payables->registerCost($request->all(), $this->actor($request));

        return response()->json(['data' => $this->present->payable($p->load(['provider', 'reservation', 'service']))], 201);
    }

    public function bankAccounts(Request $request): JsonResponse
    {
        return response()->json(['data' => BankAccount::where('active', true)->when($request->input('currency'), fn ($q, $c) => $q->where('currency', $c))
            ->orderBy('bank')->get(['id', 'bank', 'account_type', 'currency', 'number', 'label'])]);
    }
}
