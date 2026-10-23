<?php

namespace App\Http\Controllers\Api\Crm;

use App\Services\Crm\SalesDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /dashboard/sales (spec 01 §6.6, §7.4): siempre "Mi cartera" del usuario. */
class SalesDashboardController extends CrmController
{
    public function __invoke(Request $request, SalesDashboard $dashboard): JsonResponse
    {
        $user = $this->actor($request);

        return response()->json(['data' => $dashboard->build($user), 'meta' => ['permissions' => [
            'new_lead' => $user->hasPermissionTo('crm.leads.manage'),
            'new_quote' => $user->hasPermissionTo('quotes.create'),
            'take' => $user->hasPermissionTo('crm.leads.manage'),
            'complete' => $user->hasPermissionTo('crm.activities.manage'),
        ]]]);
    }
}
