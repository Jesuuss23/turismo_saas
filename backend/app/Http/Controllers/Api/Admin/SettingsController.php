<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Admin\TenantSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Configuración del tenant (PROC-003, spec 09 §6.4). */
class SettingsController extends Controller
{
    public function __construct(private readonly TenantSettings $settings) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->settings->all()]);
    }

    public function update(Request $request, string $section): JsonResponse
    {
        if (! isset(TenantSettings::LABELS[$section])) {
            throw ApiException::notFound();
        }

        return response()->json($this->settings->update($section, $request->all(), $request->header('If-Match'), $request->user()));
    }

    public function fxRates(): JsonResponse
    {
        return response()->json(['data' => $this->settings->fxHistory()]);
    }

    public function sequences(): JsonResponse
    {
        return response()->json(['data' => $this->settings->sequences()]);
    }

    public function updateSequences(Request $request): JsonResponse
    {
        $data = $request->validate(['items' => 'required|array|min:1', 'items.*.doc_type' => 'required|string', 'items.*.prefix' => 'nullable|string|max:10',
            'items.*.next_number' => 'required|integer|min:1']);

        return response()->json($this->settings->updateSequences($data['items'], $request->user()));
    }

    public function addSequence(Request $request): JsonResponse
    {
        return response()->json($this->settings->addSequence($request->all(), $request->user()), 201);
    }
}
