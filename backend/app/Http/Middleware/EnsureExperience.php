<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-BR-05: cada grupo de rutas API pertenece a una experiencia (backoffice, portal, mobile).
 * Uso: `experience:backoffice` o `experience:portal,mobile`.
 */
class EnsureExperience
{
    public function handle(Request $request, Closure $next, string ...$experiences): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (! in_array($token?->experience, $experiences, true)) {
            $role = method_exists($user, 'primaryRole') ? ($user->primaryRole()?->display_name ?? $user->roleName()) : null;
            $message = in_array('backoffice', $experiences, true)
                ? sprintf('Esta pantalla pertenece al back-office interno de la agencia y no está disponible para usuarios externos (%s)', $role)
                : 'Esta sección no está disponible para su tipo de usuario';

            throw ApiException::forbidden('SEC-BR-05', $message);
        }

        return $next($request);
    }
}
