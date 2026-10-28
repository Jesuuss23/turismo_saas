<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Catalog\Booking\BookingRulesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Centro de reglas del tenant v2.2 (spec 16 §6.2 y spec 17 §6, `admin.settings.manage`). */
class BookingRulesController extends Controller
{
    public function __construct(private readonly BookingRulesService $rules) {}

    /** `?plan=starter`: vista previa por plan (solo visual). */
    public function index(Request $request): JsonResponse
    {
        $plan = $request->query('plan');

        return response()->json(['data' => $this->rules->list($plan), 'meta' => $this->rules->meta($plan)]);
    }

    public function update(Request $request, string $rule): JsonResponse
    {
        return response()->json(['data' => $this->rules->update(strtoupper($rule), $request->only(['enabled', 'params', 'scope', 'name', 'description', 'product_override']),
            $request->user(), $request->header('If-Match'))]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->rules->create($request->all(), $request->user())], 201);
    }

    public function destroy(Request $request, string $rule): JsonResponse
    {
        $this->rules->delete(strtoupper($rule), $request->user());

        return response()->json(null, 204);
    }

    public function templates(): JsonResponse
    {
        return response()->json(['data' => $this->rules->templates()]);
    }

    public function applyTemplate(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->rules->applyTemplate((string) $request->input('template'), $request->user())]);
    }

    public function reset(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->rules->reset((string) $request->input('reason'), $request->user())]);
    }

    public function simulate(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->rules->simulate($request->all())]);
    }

    public function audit(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->rules->history($request->only(['rule', 'action']))]);
    }

    public function overrides(string $rule): JsonResponse
    {
        return response()->json(['data' => $this->rules->overrides(strtoupper($rule))]);
    }
}
