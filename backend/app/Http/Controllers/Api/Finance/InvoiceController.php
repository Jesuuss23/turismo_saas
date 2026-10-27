<?php

namespace App\Http\Controllers\Api\Finance;

use App\Models\Finance\FiscalEvent;
use App\Models\Finance\Invoice;
use App\Models\Finance\Receivable;
use App\Services\Crm\CrmClock;
use App\Services\Finance\Fin;
use App\Services\Finance\FinanceIndicators;
use App\Services\Finance\Fiscal\FiscalGateway;
use App\Services\Finance\InvoiceService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** §6.6 Facturación electrónica (PROC-050). La integración OSE/PSE está por validar: el tenant opera en modo simulado. */
class InvoiceController extends FinanceController
{
    public function index(Request $request): JsonResponse
    {
        $rows = Invoice::with(['reservation:id,code', 'receivable:id,code', 'client:id,name', 'reference:id,number'])
            ->when($request->input('filter.type'), fn ($q, $t) => $q->where('doc_type', $t))
            ->when($request->input('filter.status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('filter.reservation'), fn ($q, $c) => $q->whereHas('reservation', fn ($w) => $w->where('code', $c)))
            ->when($request->input('filter.client'), fn ($q, $c) => $q->whereHas('client', fn ($w) => $w->where('code', $c)))
            ->when($request->input('filter.period'), fn ($q, $p) => $q->where('period', $p))
            ->orderByDesc('issue_date')->orderByDesc('correlative')->limit(500)->get();

        return response()->json(['data' => $rows->map(fn (Invoice $i) => $this->present->invoice($i))->all(), 'meta' => [
            'types' => Invoice::TYPE_LABELS, 'labels' => Invoice::STATUS_LABELS, 'credit_reasons' => Invoice::CREDIT_REASONS,
            'provider' => app(FiscalGateway::class)->providerName(),
        ]]);
    }

    public function summary(CrmClock $clock, FinanceIndicators $indicators): JsonResponse
    {
        $month = $clock->today()->format('Y-m');
        $inMonth = Invoice::where('period', $month)->get();
        $uninvoiced = $indicators->uninvoiced();

        return response()->json(['data' => [
            'issued_month' => $inMonth->where('doc_type', '<>', Invoice::CREDIT_NOTE)->count(),
            'issued_month_amount' => Fin::money($inMonth->where('doc_type', '<>', Invoice::CREDIT_NOTE)->whereIn('status', Invoice::COUNTED)->sum(fn (Invoice $i) => $indicators->invoicePen($i))),
            'accepted' => $inMonth->where('status', 'aceptado')->count(), 'observed' => Invoice::whereIn('status', ['observado', 'rechazado'])->count(),
            'waiting' => Invoice::whereIn('status', ['pendiente_envio', 'emitido'])->count(),
            'uninvoiced' => Fin::money(collect($uninvoiced)->sum(fn ($u) => (float) $u['pending'])), 'uninvoiced_rows' => $uninvoiced,
        ]]);
    }

    public function show(InvoiceService $invoices, string $number): JsonResponse
    {
        $i = $this->byCode(Invoice::class, $number, 'number');
        $i->load(['reservation', 'receivable', 'client', 'reference', 'lines']);
        $credits = Invoice::where('ref_invoice_id', $i->id)->orderBy('correlative')->get();

        return response()->json(['data' => $this->present->invoice($i) + [
            'issuer' => ($t = app(TenantContext::class)->tenant()) ? ['name' => $t->legal_name, 'trade_name' => $t->trade_name, 'ruc' => $t->ruc] : null,
            'client_email' => $i->client?->email, 'creditable' => $i->isCreditNote() ? '0.00' : Fin::money($invoices->creditable($i)),
            'lines' => $i->lines->map(fn ($l) => ['line_no' => $l->line_no, 'description' => $l->description, 'quantity' => (string) $l->quantity, 'unit_code' => $l->unit_code,
                'unit_value' => (string) $l->unit_value, 'igv_affectation' => $l->igv_affectation, 'line_amount' => (string) $l->line_amount, 'igv' => (string) $l->igv_amount])->all(),
            'credit_notes' => $credits->map(fn (Invoice $n) => ['number' => $n->number, 'total' => (string) $n->total_amount, 'status' => $n->status])->all(),
            'events' => FiscalEvent::where('invoice_id', $i->id)->orderBy('created_at')->get()->map(fn (FiscalEvent $e) => ['action' => $e->action, 'attempt' => $e->attempt,
                'status' => $e->status, 'code' => $e->response_code, 'message' => $e->response_message, 'at' => $e->created_at?->toIso8601String()])->all(),
            'history' => Fin::historyOf('invoice', $i->id),
            'audit' => $this->present->audit(['Comprobante' => [$i->number]]),
        ]]);
    }

    public function draft(InvoiceService $invoices, string $code): JsonResponse
    {
        return response()->json(['data' => $invoices->draft($this->byCode(Receivable::class, $code))]);
    }

    public function validateDraft(Request $request, InvoiceService $invoices): JsonResponse
    {
        return response()->json(['data' => $invoices->validate($request->all())]);
    }

    public function store(Request $request, InvoiceService $invoices): JsonResponse
    {
        $invoice = $invoices->issue($request->all(), $this->actor($request));

        return response()->json(['data' => $this->present->invoice($invoice->load(['reservation', 'receivable', 'client', 'reference']))], 202);
    }

    public function statusCheck(Request $request, InvoiceService $invoices, string $number): JsonResponse
    {
        return $this->done($invoices->statusCheck($this->byCode(Invoice::class, $number, 'number'), $this->actor($request)));
    }

    public function resend(Request $request, InvoiceService $invoices, string $number): JsonResponse
    {
        return $this->done($invoices->resend($this->byCode(Invoice::class, $number, 'number'), $request->all(), $this->actor($request)), 202);
    }

    public function deliver(Request $request, InvoiceService $invoices, string $number): JsonResponse
    {
        return $this->done($invoices->deliver($this->byCode(Invoice::class, $number, 'number'), $this->actor($request)));
    }

    private function done(Invoice $i, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->present->invoice($i->load(['reservation', 'receivable', 'client', 'reference']))], $status);
    }
}
