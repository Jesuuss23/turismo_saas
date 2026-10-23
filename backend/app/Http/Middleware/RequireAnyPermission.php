<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-BR-03: permiso spatie explícito en cada endpoint, “cualquiera de”.
 * Uso: `perm:quotes.view` o `perm:crm.clients.manage,finance.receivables`.
 * Las reglas finas (ownership, vínculo) van en la Policy del recurso.
 */
class RequireAnyPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        foreach ($permissions as $permission) {
            if ($user?->hasPermissionTo($permission)) {
                return $next($request);
            }
        }

        throw ApiException::forbidden('SEC-BR-03', 'No tiene permiso para esta acción', $permissions);
    }
}
