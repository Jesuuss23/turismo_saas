<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\Platform\EntitlementService;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec 13 §7 (PROC-062): Credenciales → Tenant → Rol → Permiso → **Feature** → Policy.
 * Uso: `feature:ecommerce`. Sin la feature en el plan (ni add-on ni override) → 403 FEATURE_NOT_IN_PLAN.
 */
class EnsureFeature
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
    ) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $tenant = $this->context->id();
        if ($tenant === null || ! $this->entitlements->enabled($tenant, $feature)) {
            $label = $tenant ? ($this->entitlements->for($tenant)[$feature]['label'] ?? $feature) : $feature;

            throw new ApiException(403, 'FEATURE_NOT_IN_PLAN', 'FEATURE_NOT_IN_PLAN', "Su plan no incluye «{$label}». Mejore su plan o contrate el add-on.", [], ['feature' => $feature]);
        }

        return $next($request);
    }
}
