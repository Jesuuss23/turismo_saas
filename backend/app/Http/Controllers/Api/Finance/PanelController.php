<?php

namespace App\Http\Controllers\Api\Finance;

use App\Services\Finance\FinanceDashboards;
use App\Services\Finance\PeriodCloseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** §6.8 Paneles de Finanzas y Contabilidad y cierre contable de periodo. */
class PanelController extends FinanceController
{
    public function finance(FinanceDashboards $dashboards): JsonResponse
    {
        return response()->json(['data' => $dashboards->finance()]);
    }

    public function accounting(FinanceDashboards $dashboards): JsonResponse
    {
        return response()->json(['data' => $dashboards->accounting()]);
    }

    public function period(PeriodCloseService $periods, string $period): JsonResponse
    {
        return response()->json(['data' => $periods->present($periods->find($period))]);
    }

    public function check(Request $request, PeriodCloseService $periods, string $period, string $check): JsonResponse
    {
        $close = $periods->find($period);
        if ($request->hasHeader('If-Match')) {
            $close->assertIfMatch($request);
        }

        return response()->json(['data' => $periods->present($periods->toggle($close, $check, $request->isMethod('put'), $this->actor($request))->refresh())]);
    }

    public function close(Request $request, PeriodCloseService $periods, string $period): JsonResponse
    {
        return response()->json(['data' => $periods->present($periods->close($periods->find($period), $this->actor($request))->refresh())]);
    }
}
