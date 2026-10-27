<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\Iam\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-BR-24: tenant suspendido → 403 TENANT_SUSPENDED en todas las solicitudes.
 * Spec 13 §6: si la suspensión es por cobranza → 402 PAYMENT_REQUIRED, salvo `/billing/*`, la sesión y el MFA
 * para quien gestiona la facturación (así puede pagar la deuda y reactivar la cuenta).
 */
class EnsureTenantActive
{
    private const BILLING_PATHS = ['api/v1/billing', 'api/v1/billing/*', 'api/v1/me', 'api/v1/me/*', 'api/v1/auth/logout', 'api/v1/auth/mfa/verify'];

    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->tenant();
        if (! $tenant?->isActive()) {
            if ($tenant?->suspension_source === 'billing') {
                $user = $request->user();
                if ($request->is(...self::BILLING_PATHS) && $user instanceof User && $user->can('admin.billing.manage')) {
                    return $next($request);
                }

                throw new ApiException(402, 'PAYMENT_REQUIRED', 'PAYMENT_REQUIRED', 'La cuenta está suspendida por falta de pago. El administrador puede regularizarla desde Suscripción › Facturas.');
            }

            throw ApiException::tenantSuspended();
        }

        return $next($request);
    }
}
