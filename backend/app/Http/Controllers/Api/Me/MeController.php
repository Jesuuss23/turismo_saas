<?php

namespace App\Http\Controllers\Api\Me;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeResource;
use App\Security\Access;
use App\Security\AccessMap;
use App\Services\AuditLogger;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformCatalog;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function show(Request $request): MeResource
    {
        return new MeResource($request->user()->load('roles', 'sede', 'tenant'));
    }

    public function permissions(Request $request): JsonResponse
    {
        return response()->json(['permissions' => $request->user()->permissionNames()]);
    }

    /** Misma regla que Access.check (SEC-BR-04). ETag para que el frontend lo cachee. */
    public function access(Request $request): JsonResponse
    {
        $payload = $this->withEntitlements(Access::describe($request->user()->accessSubject()), $request->user()->tenant_id);
        $etag = '"'.sha1(json_encode($payload)).'"';

        if ($request->header('If-None-Match') === $etag) {
            return response()->json(null, 304)->setEtag(trim($etag, '"'));
        }

        return response()->json($payload)->setEtag(trim($etag, '"'));
    }

    /** Spec 13 §7 (capa 2, UX): el menú oculta los módulos que el plan no incluye; el backend sigue siendo la autoridad. */
    private function withEntitlements(array $payload, ?string $tenantId): array
    {
        if ($tenantId === null) {
            return $payload;
        }
        $entitlements = app(EntitlementService::class)->for($tenantId);
        $locked = array_keys(array_filter(PlatformCatalog::SCREEN_FEATURES, fn ($feature) => ! ($entitlements[$feature]['enabled'] ?? false)));
        $payload['nav'] = array_values(array_filter($payload['nav'], fn ($item) => ! in_array($item['route'] ?? '', $locked, true)));
        $payload['features'] = array_map(fn ($f) => $f['enabled'], $entitlements);

        return $payload;
    }

    /**
     * Decide una ruta de pantalla con Access.check (lo usa el middleware de Next).
     * Un denegado se audita como `acceso_denegado` (SEC-BR-07), igual que en el prototipo.
     */
    public function check(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:250']]);
        $subject = $request->user()->accessSubject();
        $path = AccessMap::normalize($data['path']);
        $decision = Access::check($subject, $path);
        $ux = $subject->ux();

        if (! $decision->allowed && $decision->redirect === null) {
            $audit->isolated('Acceso', '/'.$path, 'acceso_denegado', $subject->role, $decision->required !== [] ? $decision->reason : 'fuera del alcance del rol');
        }

        return response()->json([
            'path' => $path,
            'allowed' => $decision->allowed,
            'reason' => $decision->reason,
            'required_permissions' => $decision->required,
            'redirect' => $decision->redirect,
            'home' => $ux['home'],
            'experience' => $ux['experience'],
            'can_request_access' => $ux['experience'] === 'backoffice',
        ]);
    }

    /** Drawer “Tenant activo”: sin datos de plataforma. */
    public function tenant(TenantContext $context): JsonResponse
    {
        $tenant = $context->tenant();

        return response()->json([
            'id' => $tenant->id,
            'slug' => $tenant->slug,
            'legal_name' => $tenant->legal_name,
            'trade_name' => $tenant->trade_name,
            'ruc' => $tenant->ruc,
            'plan' => $tenant->plan?->name,
            'currency' => $tenant->currency,
            'fx_rate' => $tenant->fx_rate,
            'timezone' => $tenant->timezone,
            'igv_pct' => $tenant->igv_pct,
            'status' => $tenant->status,
            'sedes' => $tenant->sedes()->orderBy('code')->get(['id', 'code', 'name', 'city', 'is_main']),
            'isolation_notice' => 'E2E-08: los datos de este tenant están aislados; ningún otro tenant puede acceder a ellos.',
        ]);
    }
}
