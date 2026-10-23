<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** SEC-BR-24: tenant suspendido → 403 TENANT_SUSPENDED en todas las solicitudes. */
class EnsureTenantActive
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->tenant()?->isActive()) {
            throw ApiException::tenantSuspended();
        }

        return $next($request);
    }
}
