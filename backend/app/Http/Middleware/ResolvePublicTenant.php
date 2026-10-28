<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\Experience\WidgetDomain;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        // Spec 17 WG-02: el widget se identifica con la clave pública (la anterior vale 24 h tras rotarla); WG-01: CORS solo a orígenes verificados.
        $key = $request->header('X-Qhapaq-Key') ?: $request->query('key');
        if (is_string($key) && $key !== '') {
            $row = DB::connection('pgsql_platform')->table('channel_widget_config')
                ->where(fn ($q) => $q->where('public_key', $key)->orWhere(fn ($p) => $p->where('public_key_prev', $key)->where('prev_expires_at', '>', now())))->first(['tenant_id']);
            $tenant = $row ? Tenant::find($row->tenant_id) : null;
            if (! $tenant || ! $tenant->isActive()) {
                throw ApiException::notFound();
            }
            $this->context->set($tenant);
            $response = $next($request);
            $origin = (string) $request->header('Origin');
            $host = $origin !== '' ? parse_url($origin, PHP_URL_HOST) : null;
            if ($host && WidgetDomain::where('domain', strtolower($host))->where('status', 'verificado')->exists()) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Vary', 'Origin');
                $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-Qhapaq-Key, Idempotency-Key');
            }

            return $response;
        }
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
