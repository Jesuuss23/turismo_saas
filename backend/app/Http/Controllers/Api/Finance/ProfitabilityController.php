<?php

namespace App\Http\Controllers\Api\Finance;

use App\Services\Finance\Profitability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** §6.5 Rentabilidad y liquidación (PROC-048). */
class ProfitabilityController extends FinanceController
{
    public function overview(Request $request, Profitability $profitability): JsonResponse
    {
        return response()->json(['data' => $profitability->overview($request->input('filter', []) ?: [])]);
    }

    public function reservations(Request $request, Profitability $profitability): JsonResponse
    {
        return response()->json(['data' => $profitability->rows($request->input('filter', []) ?: [])]);
    }

    public function settlement(Profitability $profitability, string $code): JsonResponse
    {
        $r = $this->reservation($code);

        return response()->json(['data' => $profitability->settlement($r) + ['audit' => $this->present->audit(['Reserva' => [$r->code]])]]);
    }

    public function settle(Request $request, Profitability $profitability, string $code): JsonResponse
    {
        $r = $this->reservation($code);
        if ($request->hasHeader('If-Match')) {
            $r->assertIfMatch($request);
        }
        $profitability->settle($r, $this->actor($request));

        return response()->json(['data' => $profitability->settlement($r->refresh())], 201);
    }

    public function accountStatement(Request $request, Profitability $profitability, string $code): JsonResponse
    {
        return response()->json(['data' => $profitability->accountStatement($this->reservation($code), $this->actor($request))], 201);
    }
}
