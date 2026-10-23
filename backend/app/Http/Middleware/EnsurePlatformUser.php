<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\Iam\PlatformUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** SEC-BR-06: rutas /api/v1/platform/* solo para usuarios de plataforma con MFA verificado. */
class EnsurePlatformUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof PlatformUser || $user->currentAccessToken()?->experience !== 'platform' || $user->status !== 'activo') {
            throw ApiException::forbidden('SEC-BR-06', 'fuera del alcance del rol');
        }

        return $next($request);
    }
}
