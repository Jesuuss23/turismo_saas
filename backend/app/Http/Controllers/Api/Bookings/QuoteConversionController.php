<?php

namespace App\Http\Controllers\Api\Bookings;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Bookings\ReservationPresenter;
use App\Models\Quotes\Quote;
use App\Services\Bookings\ReservationService;
use App\Services\Quotes\QuoteAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** PROC-025 (§4.3): modal "Convertir en reserva" y conversión idempotente (Idempotency-Key + If-Match + lock). */
class QuoteConversionController extends Controller
{
    public function __construct(
        private readonly QuoteAccess $access,
        private readonly ReservationService $reservations,
        private readonly ReservationPresenter $present,
    ) {}

    public function preview(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->reservations->conversionPreview($this->find($code, $request))]);
    }

    /** 201 con la reserva creada; 200 con la existente si la cotización ya se convirtió (RT-22). */
    public function convert(Request $request, string $code): JsonResponse
    {
        $quote = $this->find($code, $request);
        $header = $request->header('If-Match');
        if ($header === null || $header === '') {
            throw new ApiException(428, 'PRECONDITION_REQUIRED', 'SEC-BR-17', 'Este recurso exige el header If-Match con su lock_version');
        }
        [$reservation, $created] = $this->reservations->convert($quote, (int) trim(preg_replace('/^W\//', '', $header), '"'), $request->user());

        return response()->json(['data' => $this->present->detail($reservation, $request->user()), 'meta' => ['created' => $created]], $created ? 201 : 200);
    }

    private function find(string $code, Request $request): Quote
    {
        $quote = Quote::where(fn ($q) => Str::isUuid($code) ? $q->whereKey($code) : $q->where('code', $code))->first() ?? throw ApiException::notFound();
        $this->access->ensureVisible($quote, $request->user());

        return $quote;
    }
}
