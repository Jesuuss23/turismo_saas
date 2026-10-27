<?php

namespace App\Http\Controllers\Api\Finance;

use App\Models\Finance\Invoice;
use App\Models\Finance\Payment;
use App\Models\Finance\Receivable;
use App\Services\Crm\CrmClock;
use App\Services\Finance\Fin;
use App\Services\Finance\FinanceIndicators;
use App\Services\Finance\FxRates;
use App\Services\Finance\ReceivableLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** §6.1 Cuentas por cobrar y anticipos (PROC-040/041). */
class ReceivableController extends FinanceController
{
    public function index(Request $request): JsonResponse
    {
        $rows = Receivable::with(['reservation:id,code', 'client:id,code,name'])
            ->when($request->input('filter.status'), fn ($q, $s) => $s === 'abiertas' ? $q->whereIn('status', Receivable::OPEN) : $q->where('status', $s))
            ->when($request->input('filter.currency'), fn ($q, $c) => $q->where('currency', $c))
            ->when($request->input('filter.client'), fn ($q, $c) => $q->whereHas('client', fn ($w) => $w->where('code', $c)))
            ->when($request->input('filter.reservation'), fn ($q, $c) => $q->whereHas('reservation', fn ($w) => $w->where('code', $c)))
            ->when($request->input('filter.due_from'), fn ($q, $d) => $q->whereDate('due_date', '>=', $d))
            ->when($request->input('filter.due_to'), fn ($q, $d) => $q->whereDate('due_date', '<=', $d))
            ->orderBy('due_date')->limit(500)->get();

        return response()->json(['data' => $rows->map(fn (Receivable $r) => $this->present->receivable($r))->all(), 'meta' => ['labels' => Receivable::STATUS_LABELS]]);
    }

    public function summary(CrmClock $clock, FinanceIndicators $indicators, FxRates $fx): JsonResponse
    {
        $today = $clock->today();
        $open = Receivable::whereIn('status', Receivable::OPEN)->with(['reservation:id,code', 'client:id,name'])->orderBy('due_date')->get();
        $pen = fn ($set) => Fin::money($set->sum(fn (Receivable $r) => $indicators->receivablePen($r)));
        $soon = $open->filter(fn (Receivable $r) => $r->status !== 'vencida' && $r->due_date->toDateString() <= $today->copy()->addDays(7)->toDateString());
        $collected = Payment::where('status', 'aplicado')->where('kind', '<>', 'reembolso')->whereRaw("to_char(paid_on, 'YYYY-MM') = ?", [$today->format('Y-m')])->get();
        $uninvoiced = $indicators->uninvoiced();

        return response()->json(['data' => [
            'kpis' => ['open' => $pen($open), 'open_count' => $open->count(), 'overdue' => $pen($open->where('status', 'vencida')),
                'overdue_count' => $open->where('status', 'vencida')->count(), 'due_7d' => $pen($soon), 'due_7d_count' => $soon->count(),
                'collected_month' => Fin::money($collected->sum(fn (Payment $p) => $fx->toPen($p->amount, $p->currency, $p->fx_rate))),
                'uninvoiced' => Fin::money(collect($uninvoiced)->sum(fn ($u) => (float) $u['pending'])), 'uninvoiced_count' => count($uninvoiced)],
            'aging' => $indicators->aging(4),
            'upcoming' => $open->where('status', '<>', 'vencida')->take(6)->map(fn (Receivable $r) => $this->present->receivable($r))->values()->all(),
        ]]);
    }

    public function show(string $code): JsonResponse
    {
        $r = $this->byCode(Receivable::class, $code);
        $r->load(['reservation', 'client']);
        $payments = Payment::where('receivable_id', $r->id)->orWhere(fn ($q) => $q->where('reservation_id', $r->reservation_id)->where('kind', 'reembolso'))
            ->with(['receivable:id,code', 'reservation:id,code', 'client:id,name', 'registeredBy:id,name'])->orderByDesc('paid_on')->get();
        $invoices = $r->reservation_id ? Invoice::where('reservation_id', $r->reservation_id)->orderByDesc('issue_date')->get() : collect();

        return response()->json(['data' => $this->present->receivable($r) + [
            'client_email' => $r->client?->email, 'client_phone' => $r->client?->phone, 'reservation_status' => $r->reservation?->status,
            'cancel_reason' => $r->cancel_reason, 'reminders' => $r->reminders()->orderByDesc('sent_at')->get()->map(fn ($m) => ['channels' => $m->channels,
                'sent_at' => $m->sent_at?->toIso8601String(), 'balance' => (string) $m->balance_at_send])->all(),
            'payments' => $payments->map(fn (Payment $p) => $this->present->payment($p))->all(),
            'invoices' => $invoices->map(fn (Invoice $i) => $this->present->invoice($i))->all(),
            'audit' => $this->present->audit(['Cuenta por cobrar' => [$r->code], 'Pago' => $payments->pluck('code')->all()]),
            'notifications' => $this->present->notifications('receivable', [$r->id]),
        ]]);
    }

    public function account(ReceivableLedger $ledger, string $code): JsonResponse
    {
        return response()->json(['data' => $ledger->account($this->reservation($code))]);
    }

    public function advance(Request $request, ReceivableLedger $ledger): JsonResponse
    {
        $data = $request->validate(['reservation_id' => 'required|string', 'amount' => 'required|numeric', 'due_date' => 'required|date',
            'concept' => 'nullable|string|max:255', 'notify' => 'nullable|boolean', 'fx_rate' => 'nullable|numeric|gt:0'],
            ['reservation_id.required' => 'Seleccione la reserva.', 'amount.required' => 'Indique el importe.', 'due_date.required' => 'Indique el vencimiento.']);
        $reservation = $this->reservation($data['reservation_id']);
        if (! empty($request->header('If-Match'))) {
            $reservation->assertIfMatch($request);
        }
        $r = $ledger->advance($reservation, $data, $this->actor($request));

        return response()->json(['data' => $this->present->receivable($r->load(['reservation', 'client']))], 201);
    }

    public function remind(Request $request, ReceivableLedger $ledger, string $code): JsonResponse
    {
        $reminder = $ledger->remind($this->byCode(Receivable::class, $code), $this->actor($request));

        return response()->json(['data' => ['channels' => $reminder->channels, 'sent_at' => $reminder->sent_at?->toIso8601String()]], 201);
    }
}
