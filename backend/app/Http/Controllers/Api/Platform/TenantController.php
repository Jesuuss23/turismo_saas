<?php

namespace App\Http\Controllers\Api\Platform;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Security\Access;
use App\Security\AccessMap;
use App\Services\Admin\AdminCatalog;
use App\Services\Admin\AuditQuery;
use App\Services\Admin\IntegrationAdmin;
use App\Services\Admin\TenantConsole;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Consola de plataforma (PROC-001, spec 09 §6.1): tenants, planes, salud, auditoría técnica y acceso por pantalla. */
class TenantController extends Controller
{
    public function __construct(private readonly TenantConsole $console) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->console->list($request->only(['plan', 'status', 'q'])));
    }

    public function wizard(): JsonResponse
    {
        return response()->json(['data' => $this->console->wizard()]);
    }

    public function validateStep(Request $request): JsonResponse
    {
        $data = $request->validate(['step' => 'required|integer|min:1|max:11']);

        return response()->json($this->console->validateStep((int) $data['step'], $request->except('step')));
    }

    public function store(Request $request): JsonResponse
    {
        $key = $request->header('Idempotency-Key') ?: throw ApiException::invalid('Idempotency-Key', 'El alta exige el header Idempotency-Key');

        return response()->json(['data' => $this->console->store($request->all(), $key, $request->user())], 201);
    }

    public function show(string $tenant): JsonResponse
    {
        return response()->json(['data' => $this->console->show($tenant)]);
    }

    public function suspend(Request $request, string $tenant): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500'], ['reason.required' => 'TEN-BR-08: el motivo es obligatorio.']);

        return response()->json(['data' => $this->console->suspend($tenant, $data['reason'], $request->header('If-Match'), $request->user())]);
    }

    public function reactivate(Request $request, string $tenant): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500'], ['reason.required' => 'TEN-BR-08: el motivo es obligatorio.']);

        return response()->json(['data' => $this->console->reactivate($tenant, $data['reason'], $request->header('If-Match'), $request->user())]);
    }

    public function changePlan(Request $request, string $tenant): JsonResponse
    {
        $data = $request->validate(['plan_id' => 'required|string', 'effective' => ['nullable', Rule::in(['immediate', 'next_cycle'])]]);

        return response()->json(['data' => $this->console->changePlan($tenant, $data['plan_id'], $data['effective'] ?? 'immediate', $request->header('If-Match'), $request->user())]);
    }

    public function plans(): JsonResponse
    {
        return response()->json(['data' => array_values($this->console->plans()), 'entitlements' => $this->console->entitlements()]);
    }

    public function health(): JsonResponse
    {
        return response()->json($this->console->health());
    }

    public function isolation(Request $request): JsonResponse
    {
        $data = $request->validate(['tenant_a' => 'required|string', 'tenant_b' => 'required|string']);

        return response()->json(['data' => $this->console->isolationTest($data['tenant_a'], $data['tenant_b'], $request->user())]);
    }

    /** Vista de plataforma de Integraciones: salud por tenant + contrato de adapters y jobs (sin datos de negocio). */
    public function integrations(): JsonResponse
    {
        return response()->json([
            'health' => $this->console->health()['data'],
            'adapters' => collect(AdminCatalog::ADAPTERS)->map(fn ($a, $code) => ['code' => $code, 'name' => $a[0], 'kind' => $a[1], 'default_status' => $a[2],
                'contract' => IntegrationAdmin::CONTRACTS[$a[1]] ?? null])->values()->all(),
            'jobs' => collect(AdminCatalog::JOBS)->map(fn ($j, $code) => ['code' => $code, 'name' => $j[0], 'cron' => $j[1], 'cron_label' => $j[2], 'queue' => $j[3]])->values()->all(),
        ]);
    }

    /** AUD-BR-02: solo eventos de plataforma, filtro y columna Tenant. */
    public function audit(Request $request, AuditQuery $audit): JsonResponse
    {
        return response()->json($audit->list($request->only(['period', 'entity', 'action', 'tenant', 'critical', 'q']), true));
    }

    public function auditEvent(string $event, AuditQuery $audit): JsonResponse
    {
        return response()->json(['data' => $audit->show($event, true)]);
    }

    public function access(Request $request): JsonResponse
    {
        return response()->json(Access::describe($request->user()->accessSubject()));
    }

    public function accessCheck(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:250']]);
        $subject = $request->user()->accessSubject();
        $path = AccessMap::normalize($data['path']);
        $decision = Access::check($subject, $path);
        if (! $decision->allowed) {
            $audit->isolated('Acceso', '/'.$path, 'acceso_denegado', $subject->role, 'plataforma: no opera los datos comerciales de las agencias', $request->user());
        }

        return response()->json(['path' => $path, 'allowed' => $decision->allowed,
            'reason' => $decision->allowed ? null : 'El Super Admin SaaS no opera los datos comerciales de las agencias. El soporte sobre datos de negocio requiere una sesión delegada autorizada por el Tenant Admin.',
            'required_permissions' => $decision->required, 'redirect' => $decision->redirect, 'home' => 'admin/tenants', 'experience' => 'platform', 'can_request_access' => false]);
    }
}
