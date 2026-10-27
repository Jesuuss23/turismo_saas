<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Ops\OpsPresenter;
use App\Models\Iam\User;
use App\Models\Ops\Driver;
use App\Models\Ops\Guide;
use App\Models\Ops\MobileDevice;
use App\Services\Ops\OperatorApp;
use App\Services\Ops\OpsAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Spec 05 §6.7: app del operador (guía/conductor). El recurso sale del usuario (OPS-BR-52); un interno con
 * `operations.assign` previsualiza con `?as=G01` ("Ver como recurso"). El dispositivo viaja en `X-Device-Id`.
 */
class OperatorController extends Controller
{
    public function __construct(
        private readonly OperatorApp $app,
        private readonly OpsAccess $access,
        private readonly OpsPresenter $present,
    ) {}

    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => 'required|uuid', 'app' => ['required', Rule::in(['operador', 'pasajero'])], 'platform' => ['required', Rule::in(['ios', 'android', 'web'])],
            'app_version' => 'required|string|max:20', 'expo_push_token' => 'nullable|string|max:200',
        ]);
        $device = $this->app->registerDevice($request->user(), $data);

        return response()->json(['data' => ['id' => $device->id, 'app' => $device->app, 'platform' => $device->platform, 'last_seen_at' => $device->last_seen_at?->toIso8601String()]], 201);
    }

    public function revokeDevice(Request $request, string $device): JsonResponse
    {
        $d = MobileDevice::where('user_id', $request->user()->id)->find($device) ?? throw ApiException::notFound();
        $d->forceFill(['revoked_at' => now(), 'expo_push_token' => null])->save();

        return response()->json(['data' => null]);
    }

    public function me(Request $request): JsonResponse
    {
        [$type, $resource] = $this->resource($request);

        return response()->json(['data' => $this->present->resourceProfile($type, $resource) + [
            'user' => ['name' => $request->user()->name, 'email' => $request->user()->email],
            'device' => $this->device($request)?->only(['id', 'platform', 'app_version', 'last_sync_at']),
        ], 'meta' => $this->previewMeta($request)]);
    }

    public function bootstrap(Request $request): JsonResponse
    {
        [$type, $resource] = $this->resource($request);

        return response()->json(['data' => $this->app->bootstrap($type, $resource, $this->device($request)), 'meta' => $this->previewMeta($request)]);
    }

    public function assignment(Request $request, string $assignment): JsonResponse
    {
        [$type, $resource] = $this->resource($request);

        return response()->json(['data' => $this->present->mobileAssignment($this->app->assignmentFor($type, $resource, $assignment), $type)]);
    }

    /** Una acción (`POST /actions`): 200 con `{result, conflict_rule?, message?, assignment}`; las rechazadas también son 200. */
    public function action(Request $request): JsonResponse
    {
        [$type, $resource] = $this->resource($request);
        $action = $request->validate($this->actionRules());

        return response()->json(['data' => $this->app->apply($action, $request->user(), $type, $resource, $this->device($request))]);
    }

    public function sync(Request $request): JsonResponse
    {
        [$type, $resource] = $this->resource($request);
        $data = $request->validate(['actions' => 'present|array|max:'.OperatorApp::MAX_BATCH] + collect($this->actionRules())->mapWithKeys(fn ($r, $k) => ["actions.*.{$k}" => $r])->all());
        $results = $this->app->sync($data['actions'], $request->user(), $type, $resource, $this->device($request));

        return response()->json(['data' => $results, 'meta' => ['synced_at' => now()->toIso8601String()]]);
    }

    public function incidents(Request $request): JsonResponse
    {
        [$type, $resource] = $this->resource($request);

        return response()->json(['data' => $this->app->incidentsFor($resource, $type)->map(fn ($i) => $this->present->incidentRow($i))->values()->all()]);
    }

    /** Alternativa directa a `actions` (type=incident). */
    public function storeIncident(Request $request): JsonResponse
    {
        [$type, $resource] = $this->resource($request);
        $data = $request->validate(['client_action_id' => 'required|uuid', 'assignment_id' => 'required|uuid', 'occurred_at' => 'nullable|date', 'payload' => 'required|array']);

        return response()->json(['data' => $this->app->apply($data + ['type' => 'incident'], $request->user(), $type, $resource, $this->device($request))], 201);
    }

    private function actionRules(): array
    {
        return [
            'client_action_id' => 'required|uuid', 'type' => ['required', Rule::in(OperatorApp::TYPES)], 'assignment_id' => 'required|uuid',
            'occurred_at' => 'nullable|date', 'offline' => 'boolean', 'attempts' => 'nullable|integer|min:1', 'payload' => 'nullable|array',
        ];
    }

    /** @return array{0: string, 1: Guide|Driver} */
    private function resource(Request $request): array
    {
        return $this->access->resource($request->user(), $request->query('as'));
    }

    private function device(Request $request): ?MobileDevice
    {
        $id = $request->header('X-Device-Id');

        return $id && preg_match('/^[0-9a-f-]{36}$/i', $id) ? MobileDevice::where('user_id', $request->user()->id)->whereNull('revoked_at')->find($id) : null;
    }

    /** Selector "Ver como recurso" (§2): solo internos con `operations.assign`. */
    private function previewMeta(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->isExternal() || ! $user->hasPermissionTo(OpsAccess::ASSIGN)) {
            return ['preview' => false];
        }

        return ['preview' => true, 'resources' => Guide::orderBy('code')->get()->map(fn ($g) => ['code' => $g->code, 'name' => $g->full_name, 'type' => 'guide'])
            ->concat(Driver::orderBy('code')->get()->map(fn ($d) => ['code' => $d->code, 'name' => $d->full_name, 'type' => 'driver']))->values()->all()];
    }
}
