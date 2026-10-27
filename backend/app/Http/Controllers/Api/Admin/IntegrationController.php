<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Admin\IntegrationAdapter;
use App\Models\Admin\IntegrationError;
use App\Models\Admin\ScheduledJob;
use App\Models\Iam\User;
use App\Services\Admin\IntegrationAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Integraciones, scheduler y errores (PROC-064/065, spec 09 §6.6). */
class IntegrationController extends Controller
{
    public function __construct(private readonly IntegrationAdmin $integrations) {}

    public function index(): JsonResponse
    {
        return response()->json($this->integrations->adapters());
    }

    public function test(Request $request, string $adapter): JsonResponse
    {
        return response()->json($this->integrations->test($this->adapter($adapter), $request->user()));
    }

    public function configure(Request $request, string $adapter): JsonResponse
    {
        return response()->json($this->integrations->configure($this->adapter($adapter), $request->all(), $request->user()));
    }

    public function jobs(): JsonResponse
    {
        return response()->json($this->integrations->jobs());
    }

    public function run(Request $request, string $job): JsonResponse
    {
        return response()->json($this->integrations->run($this->job($job), $request->user()));
    }

    public function pause(Request $request, string $job, string $action): JsonResponse
    {
        return response()->json($this->integrations->pause($this->job($job), $action === 'pause', $request->user()));
    }

    /** JOB-BR-03: solo envíos programados; además de admin.settings.manage, su dueño con reports.export. */
    public function destroy(Request $request, string $job): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = $this->job($job);
        if (! $user->hasPermissionTo('admin.settings.manage') && ! ($model->owner_user_id === $user->id && $user->hasPermissionTo('reports.export'))) {
            throw ApiException::forbidden('JOB-BR-03', 'Solo el administrador o quien programó el envío puede eliminarlo', ['admin.settings.manage', 'reports.export']);
        }
        $this->integrations->delete($model, $user);

        return response()->json(['message' => "{$model->code} eliminado"]);
    }

    public function errors(Request $request): JsonResponse
    {
        return response()->json($this->integrations->errors($request->only(['status', 'class', 'source'])));
    }

    public function retry(Request $request, string $error): JsonResponse
    {
        return response()->json($this->integrations->retry($this->error($error), $request->user()));
    }

    public function resolve(Request $request, string $error): JsonResponse
    {
        $data = $request->validate(['resolution' => 'nullable|string|max:500']);

        return response()->json($this->integrations->resolve($this->error($error), $data['resolution'] ?? null, $request->user()));
    }

    private function adapter(string $key): IntegrationAdapter
    {
        return (Str::isUuid($key) ? IntegrationAdapter::whereKey($key) : IntegrationAdapter::where('code', $key))->first() ?? throw ApiException::notFound();
    }

    private function job(string $key): ScheduledJob
    {
        return (Str::isUuid($key) ? ScheduledJob::whereKey($key) : ScheduledJob::where('code', $key))->first() ?? throw ApiException::notFound();
    }

    private function error(string $key): IntegrationError
    {
        return (Str::isUuid($key) ? IntegrationError::whereKey($key) : IntegrationError::where('code', (int) preg_replace('/\D/', '', $key)))->first() ?? throw ApiException::notFound();
    }
}
