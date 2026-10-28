<?php

namespace App\Http\Controllers\Api\Experience;

use App\Http\Controllers\Controller;
use App\Services\Experience\CurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Monedas y tipos de cambio (spec 17 §6, `admin.settings.manage | finance.treasury`; activar y cobro solo admin). */
class CurrenciesController extends Controller
{
    public function __construct(private readonly CurrencyService $fx) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->fx->list(), 'meta' => ['can_manage' => $request->user()->hasPermissionTo('admin.settings.manage')]]);
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $result = $this->fx->update($code, $request->only(['rate_pen', 'active', 'is_charge', 'confirm']), $request->user(), $request->header('If-Match'));

        return response()->json(['data' => $result, 'meta' => ['warnings' => $result['warnings']]]);
    }

    public function rates(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->fx->history($code, (int) $request->query('days', 30))]);
    }

    public function fxRates(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->fx->history((string) $request->query('currency', 'USD'), (int) $request->query('days', 30))]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $result = $this->fx->refresh($request->user());

        return response()->json(['data' => $result, 'meta' => ['warnings' => $result['warnings']]]);
    }

    public function providers(): JsonResponse
    {
        return response()->json(['data' => $this->fx->providers()]);
    }

    public function testProvider(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->fx->testProvider($code, $request->user())]);
    }

    public function settings(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->fx->saveSettings($request->only(['rate_provider_id', 'fx_alert_pct', 'fx_margin_pct']), $request->user())]);
    }

    public function convert(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->fx->convert($request->query('amount'), (string) $request->query('from', 'PEN'), (string) $request->query('to', 'USD'))]);
    }
}
