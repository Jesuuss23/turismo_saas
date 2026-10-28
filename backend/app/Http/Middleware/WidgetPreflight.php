<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Preflight CORS del widget (spec 17 WG-01): la petición OPTIONS no trae la clave pública, así que se acepta si el Origin es un
 * dominio verificado de algún tenant (lectura de plataforma). La petición real vuelve a validar el origen contra el tenant de la clave.
 */
class WidgetPreflight
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('OPTIONS') || ! $request->is('api/v1/public/*') || ! $request->headers->has('Access-Control-Request-Method')) {
            return $next($request);
        }
        $origin = (string) $request->header('Origin');
        $host = $origin !== '' ? strtolower((string) parse_url($origin, PHP_URL_HOST)) : '';
        if ($host === '' || ! DB::connection('pgsql_platform')->table('channel_widget_domains')->where('domain', $host)->where('status', 'verificado')->exists()) {
            return response('', 204);
        }

        return response('', 204, [
            'Access-Control-Allow-Origin' => $origin, 'Vary' => 'Origin', 'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, X-Qhapaq-Key, Idempotency-Key', 'Access-Control-Max-Age' => '600',
        ]);
    }
}
