<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\Iam\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-BR-01/02: el tenant sale solo del token y se contrasta con el host {slug}.dominio.
 * Se ignora cualquier tenant_id / X-Tenant-Id enviado por el cliente; una ruta que fija
 * otro tenant responde 403 TENANT_SCOPE_VIOLATION.
 */
class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (! $user instanceof User || $token?->tenant_id === null || $token->tenant_id !== $user->tenant_id) {
            throw ApiException::forbidden('SEC-BR-01', 'La solicitud no pertenece a un tenant válido');
        }

        $this->context->set($token->tenant_id);
        $tenant = $this->context->tenant() ?? throw ApiException::unauthenticated();

        $slug = $this->hostSlug($request);
        if ($slug !== null && $slug !== $tenant->slug) {
            throw ApiException::tenantScopeViolation();
        }

        foreach (['tenant', 'tenantId', 'tenant_id'] as $param) {
            $value = $request->route($param);
            if ($value !== null && $value !== $tenant->getKey() && $value !== $tenant->slug) {
                throw ApiException::tenantScopeViolation();
            }
        }

        // El cliente nunca elige tenant: se descarta lo que envíe.
        $request->headers->remove('X-Tenant-Id');
        $request->query->remove('tenant_id');
        $request->request->remove('tenant_id');
        if ($request->isJson()) {
            $request->json()->remove('tenant_id');
        }

        return $next($request);
    }

    private function hostSlug(Request $request): ?string
    {
        $base = config('turismo.tenant_base_domain');
        $host = $request->getHost();

        return str_ends_with($host, '.'.$base) ? substr($host, 0, -strlen('.'.$base)) : null;
    }
}
