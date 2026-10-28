<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Catalog\Booking\BookingRulesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Centro de reglas del tenant (spec 16 §6.2, `admin.settings.manage`). */
class BookingRulesController extends Controller
{
    public function __construct(private readonly BookingRulesService $rules) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->rules->list()]);
    }

    public function update(Request $request, string $rule): JsonResponse
    {
        return response()->json(['data' => $this->rules->update(strtoupper($rule), $request->only(['enabled', 'params', 'scope', 'name', 'description', 'product_override']), $request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->rules->create($request->all(), $request->user())], 201);
    }
}
