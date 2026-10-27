<?php

namespace App\Http\Controllers\Api\Ext;

use App\Http\Controllers\Controller;
use App\Services\Integrations\ExtApiService;
use App\Services\Integrations\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** API pública `/api/v1/ext/*` (spec 13, guard api-key con scope por ruta). */
class ExtApiController extends Controller
{
    public function __construct(private readonly ExtApiService $api) {}

    public function reservations(Request $request): JsonResponse
    {
        return response()->json($this->api->reservations($request->only(['status', 'from', 'to', 'page'])));
    }

    public function reservation(string $code): JsonResponse
    {
        return response()->json(['data' => $this->api->reservation($code)]);
    }

    public function products(Request $request): JsonResponse
    {
        return response()->json($this->api->products($request->only(['q', 'page'])));
    }

    public function clients(Request $request): JsonResponse
    {
        return response()->json($this->api->clients($request->only(['q', 'page'])));
    }

    public function createLead(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->api->createLead($request->all())], 201);
    }

    public function webhooks(WebhookService $webhooks): JsonResponse
    {
        return response()->json(['data' => $webhooks->list()]);
    }

    public function createWebhook(Request $request, WebhookService $webhooks): JsonResponse
    {
        $data = $request->validate(['url' => 'required|string|max:500', 'events' => 'required|array|min:1', 'events.*' => 'string', 'description' => 'nullable|string|max:200']);
        // El webhook se atribuye a quien creó la API key (la key autentica al tenant, no a un usuario).
        $result = $webhooks->create($data, null, $request->attributes->get('api_key')->created_by);

        return response()->json(['data' => $result], 201);
    }
}
