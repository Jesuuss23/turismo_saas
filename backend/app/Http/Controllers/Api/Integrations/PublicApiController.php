<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Http\Controllers\Controller;
use App\Services\Integrations\ApiKeyService;
use App\Services\Integrations\OAuthAppService;
use App\Services\Integrations\OpenApiDocument;
use App\Services\Integrations\WebhookService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Spec 13 §6 `/integrations/*` (admin.api.manage): API keys, webhooks, entregas, límites, docs y apps OAuth del tenant. */
class PublicApiController extends Controller
{
    public function __construct(
        private readonly ApiKeyService $keys,
        private readonly WebhookService $webhooks,
        private readonly OAuthAppService $apps,
    ) {}

    /** Catálogos de la pantalla y el estado del plan (bloqueo si no incluye la API). */
    public function overview(Request $request, EntitlementService $entitlements): JsonResponse
    {
        $ent = $entitlements->for($request->user()->tenant_id);

        return response()->json(['data' => [
            'scopes' => collect(PlatformCatalog::SCOPES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'events' => collect(PlatformCatalog::WEBHOOK_EVENTS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'features' => ['api_access' => $ent['api_access']['enabled'] ?? false, 'webhooks' => $ent['webhooks']['enabled'] ?? false, 'oauth_apps' => $ent['oauth_apps']['enabled'] ?? false],
            'api_version' => PlatformCatalog::API_VERSION,
        ]]);
    }

    public function keys(): JsonResponse
    {
        return response()->json(['data' => $this->keys->list()]);
    }

    public function createKey(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'environment' => 'required|in:live,test', 'scopes' => 'required|array|min:1',
            'scopes.*' => 'string', 'ip_allowlist' => 'nullable', 'expires_at' => 'nullable|date_format:Y-m-d']);

        return response()->json(['data' => $this->keys->create($data, $request->user())], 201);
    }

    public function revokeKey(Request $request, string $key): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:300'], ['reason.required' => 'El motivo de la revocación es obligatorio.']);

        return response()->json(['data' => $this->keys->revoke($key, $data['reason'], $request->user())]);
    }

    public function limits(): JsonResponse
    {
        return response()->json(['data' => $this->keys->limitsView()]);
    }

    public function webhooks(): JsonResponse
    {
        return response()->json(['data' => $this->webhooks->list()]);
    }

    public function createWebhook(Request $request): JsonResponse
    {
        $data = $request->validate(['url' => 'required|string|max:500', 'events' => 'required|array|min:1', 'events.*' => 'string', 'description' => 'nullable|string|max:200']);

        return response()->json(['data' => $this->webhooks->create($data, $request->user())], 201);
    }

    public function updateWebhook(Request $request, string $webhook): JsonResponse
    {
        $data = $request->validate(['url' => 'sometimes|string|max:500', 'events' => 'sometimes|array|min:1', 'events.*' => 'string',
            'description' => 'sometimes|nullable|string|max:200', 'status' => 'sometimes|in:active,paused']);

        return response()->json(['data' => $this->webhooks->update($webhook, $data, $request->user())]);
    }

    public function deleteWebhook(Request $request, string $webhook): JsonResponse
    {
        $this->webhooks->delete($webhook, $request->user());

        return response()->json(['message' => 'Webhook eliminado.']);
    }

    public function rotateSecret(Request $request, string $webhook): JsonResponse
    {
        return response()->json(['data' => $this->webhooks->rotate($webhook, $request->user())]);
    }

    public function ping(Request $request, string $webhook): JsonResponse
    {
        return response()->json(['data' => $this->webhooks->ping($webhook, $request->user())]);
    }

    public function deliveries(Request $request): JsonResponse
    {
        $filter = (array) $request->query('filter', []);

        return response()->json(['data' => $this->webhooks->deliveries($filter + $request->only(['status', 'webhook', 'event']))]);
    }

    public function delivery(string $delivery): JsonResponse
    {
        return response()->json(['data' => $this->webhooks->delivery($delivery)]);
    }

    public function redeliver(Request $request, string $delivery): JsonResponse
    {
        return response()->json(['data' => $this->webhooks->redeliver($delivery, $request->user())]);
    }

    public function oauthApps(): JsonResponse
    {
        return response()->json(['data' => $this->apps->list()]);
    }

    public function createOauthApp(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'vendor' => 'nullable|string|max:120', 'redirect_uris' => 'required',
            'allowed_scopes' => 'required|array|min:1', 'allowed_scopes.*' => 'string']);

        return response()->json(['data' => $this->apps->create($data, $request->user())], 201);
    }

    public function rotateOauthSecret(Request $request, string $app): JsonResponse
    {
        return response()->json(['data' => $this->apps->rotate($app, $request->user())]);
    }

    public function revokeOauthApp(Request $request, string $app): JsonResponse
    {
        return response()->json(['data' => $this->apps->revoke($app, $request->user())]);
    }

    /** GET /docs/openapi.json (público). */
    public function openapi(): JsonResponse
    {
        return response()->json(OpenApiDocument::build((string) config('app.url')));
    }
}
