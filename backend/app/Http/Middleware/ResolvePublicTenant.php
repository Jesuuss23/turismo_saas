<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoints públicos sin sesión (tienda, CMS, acceso del pasajero, webhooks; spec 08 §6): el tenant sale del host {slug}.dominio.
 * Fuera de un subdominio de tenant (desarrollo, BFF del sitio) se acepta X-Tenant-Slug; nunca un id.
 */
class ResolvePublicTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $base = config('turismo.tenant_base_domain');
        $host = $request->getHost();
        $slug = str_ends_with($host, '.'.$base) ? substr($host, 0, -strlen('.'.$base)) : ($request->header('X-Tenant-Slug') ?: ($request->query('tenant') ?: $request->route('tenant')));
        $tenant = $slug ? Tenant::where('slug', $slug)->first() : null;
        if (! $tenant || ! $tenant->isActive()) {
            throw ApiException::notFound();
        }
        $this->context->set($tenant);

        return $next($request);
    }
}
