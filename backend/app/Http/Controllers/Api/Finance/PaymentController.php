<?php

namespace App\Http\Controllers\Api\Finance;

use App\Exceptions\ApiException;
use App\Models\Bookings\Reservation;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentMethod;
use App\Models\Finance\Receivable;
use App\Services\Bookings\BookingAccess;
use App\Services\Crm\CrmClock;
use App\Services\Finance\Fin;
use App\Services\Finance\FxRates;
use App\Services\Finance\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** §6.2 Pagos, aplicación y reversión (PROC-042/043). `POST /finance/payments` también es el atajo de la reserva (RES-BR-40 / RT-9). */
class PaymentController extends FinanceController
{
    public function index(Request $request): JsonResponse
    {
        $rows = Payment::with(['receivable:id,code', 'reservation:id,code', 'client:id,name', 'registeredBy:id,name'])
            ->when($request->input('filter.status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('filter.method'), fn ($q, $m) => $q->where('method', $m))
            ->when($request->input('filter.reservation'), fn ($q, $c) => $q->whereHas('reservation', fn ($w) => $w->where('code', $c)))
            ->orderByDesc('paid_on')->orderByDesc('created_at')->limit(500)->get();

        return response()->json(['data' => $rows->map(fn (Payment $p) => $this->present->payment($p))->all(), 'meta' => [
            'labels' => Payment::STATUS_LABELS, 'methods' => PaymentMethod::where('active', true)->orderBy('label')->get(['code', 'label', 'requires_reference', 'is_b2b_credit']),
        ]]);
    }

    public function summary(CrmClock $clock, FxRates $fx): JsonResponse
    {
        $month = $clock->today()->format('Y-m');
        $all = Payment::where('kind', '<>', 'reembolso')->get();
        $inMonth = fn (string $status, string $column = 'paid_on') => $all->where('status', $status)->filter(fn (Payment $p) => $p->{$column}?->format('Y-m') === $month);
        $applied = $inMonth('aplicado');

        return response()->json(['data' => [
            'pending' => $all->where('status', 'pendiente')->count(),
            'applied_month' => Fin::money($applied->sum(fn (Payment $p) => $fx->toPen($p->amount, $p->currency, $p->fx_rate))), 'applied_month_count' => $applied->count(),
            'reverted' => $all->where('status', 'revertido')->count(), 'rejected' => $all->where('status', 'rechazado')->count(),
        ]]);
    }

    public function methods(): JsonResponse
    {
        return response()->json(['data' => PaymentMethod::where('active', true)->orderBy('label')->get(['code', 'label', 'requires_reference', 'is_b2b_credit'])]);
    }

    public function show(PaymentService $payments, string $code): JsonResponse
    {
        $p = $this->byCode(Payment::class, $code);
        $p->load(['receivable', 'reservation:id,code', 'client:id,name,email', 'registeredBy:id,name']);
        $line = $payments->bankEvidence($p);
        $candidates = $p->reservation_id ? Receivable::where('reservation_id', $p->reservation_id)->whereIn('status', Receivable::OPEN)->where('currency', $p->currency)->orderBy('due_date')->get()
            ->map(fn (Receivable $r) => ['id' => $r->id, 'code' => $r->code, 'concept' => $r->concept, 'balance' => $r->balance(), 'currency' => $r->currency])->all() : [];

        return response()->json(['data' => $this->present->payment($p) + [
            'history' => Fin::historyOf('payment', $p->id), 'bank_line' => $line ? ['code' => $line->code, 'status' => $line->status, 'description' => $line->description] : null,
            'receivable_status' => $p->receivable?->status, 'receivable_balance' => $p->receivable?->balance(), 'candidates' => $candidates,
            'audit' => $this->present->audit(['Pago' => [$p->code]]),
        ]]);
    }

    public function store(Request $request, PaymentService $payments, BookingAccess $access): JsonResponse
    {
        $data = $request->validate([
            'receivable_id' => 'required|uuid', 'amount' => 'required|numeric|gt:0', 'method' => 'required|string|max:60',
            'paid_on' => 'nullable|date', 'reference' => 'nullable|string|max:200', 'fx_rate' => 'nullable|numeric|gt:0', 'note' => 'nullable|string|max:500',
            'source' => 'nullable|in:backoffice,reserva',
        ], [
            'receivable_id.required' => 'Seleccione la cuenta por cobrar (RES-BR-40).', 'amount.gt' => 'El monto debe ser mayor a 0.',
            'method.required' => 'Indique el medio de pago.',
        ]);
        $receivable = Receivable::find($data['receivable_id']) ?? throw ApiException::notFound();
        if ($receivable->reservation_id && ($reservation = Reservation::find($receivable->reservation_id))) {
            $access->ensureVisible($reservation, $this->actor($request));
        }
        $payment = $payments->register($receivable, $data, $this->actor($request), $data['source'] ?? 'backoffice');
        $receivable->refresh();

        return response()->json(['data' => ['receivable' => ['code' => $receivable->code, 'status' => $receivable->status, 'balance' => $receivable->balance()]]
            + $this->present->payment($payment->load(['reservation', 'client']))], 201);
    }

    public function validatePayment(Request $request, PaymentService $payments, string $code): JsonResponse
    {
        $data = $request->validate(['apply' => 'nullable|boolean', 'receivable_id' => 'nullable|uuid', 'note' => 'nullable|string|max:500']);
        $p = $payments->validate($this->byCode(Payment::class, $code), (bool) ($data['apply'] ?? false), $data['receivable_id'] ?? null, $this->actor($request), $data['note'] ?? null);

        return $this->done($p);
    }

    public function reject(Request $request, PaymentService $payments, string $code): JsonResponse
    {
        return $this->done($payments->reject($this->byCode(Payment::class, $code), (string) $request->input('reason', ''), $this->actor($request)));
    }

    public function retry(Request $request, PaymentService $payments, string $code): JsonResponse
    {
        return $this->done($payments->retry($this->byCode(Payment::class, $code), $this->actor($request)));
    }

    public function apply(Request $request, PaymentService $payments, string $code): JsonResponse
    {
        $p = $this->byCode(Payment::class, $code);
        $receivable = Receivable::find($request->input('receivable_id') ?: $p->receivable_id)
            ?? throw ApiException::unprocessable('RT-09', 'Regla transversal 9: el pago debe asociarse a una obligación.');

        return $this->done($payments->apply($p, $receivable, $this->actor($request)));
    }

    public function revert(Request $request, PaymentService $payments, string $code): JsonResponse
    {
        return $this->done($payments->revert($this->byCode(Payment::class, $code), (string) $request->input('reason', ''), $this->actor($request)));
    }

    private function done(Payment $p): JsonResponse
    {
        return response()->json(['data' => $this->present->payment($p->load(['receivable', 'reservation', 'client', 'registeredBy']))]);
    }
}
