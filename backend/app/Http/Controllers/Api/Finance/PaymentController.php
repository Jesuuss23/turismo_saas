<?php

namespace App\Http\Controllers\Api\Finance;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Bookings\Reservation;
use App\Models\Finance\Receivable;
use App\Services\Bookings\BookingAccess;
use App\Services\Bookings\ReceivableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** PROC-042 (atajo, RES-BR-40 / RT-9): pago aplicado a una CxC desde la reserva. */
class PaymentController extends Controller
{
    public function store(Request $request, ReceivableService $receivables, BookingAccess $access): JsonResponse
    {
        $data = $request->validate([
            'receivable_id' => 'required|uuid', 'amount' => 'required|numeric|gt:0', 'method' => 'required|string|max:60',
            'paid_on' => 'nullable|date|before_or_equal:today', 'reference' => 'nullable|string|max:200',
        ], [
            'receivable_id.required' => 'Seleccione la cuenta por cobrar (RES-BR-40).', 'amount.gt' => 'El monto debe ser mayor a 0.',
            'method.required' => 'Indique el medio de pago.', 'paid_on.before_or_equal' => 'La fecha de pago no puede ser futura.',
        ]);
        $receivable = Receivable::find($data['receivable_id']) ?? throw ApiException::notFound();
        if ($receivable->reservation_id && ($reservation = Reservation::find($receivable->reservation_id))) {
            $access->ensureVisible($reservation, $request->user());
        }
        $payment = $receivables->applyPayment($receivable, $data, $request->user());

        return response()->json(['data' => [
            'id' => $payment->id, 'code' => $payment->code, 'amount' => (string) $payment->amount, 'currency' => $payment->currency, 'status' => $payment->status,
            'receivable' => ['code' => $receivable->code, 'status' => $receivable->fresh()->status, 'balance' => $receivable->fresh()->balance()],
        ]], 201);
    }
}
