<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\Supplier\SupplierAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Extranet de proveedores (spec 06 §6): usuario del rol Proveedor vinculado a un proveedor (SUP-BR-01) y, si se indica,
 * con el permiso `supplier.*` dentro de su `provider_role`. Uso: `supplier` o `supplier:supplier.requests.respond`.
 */
class EnsureSupplier
{
    public function __construct(private readonly SupplierAccess $access) {}

    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $user = $request->user();
        if ($user?->roleName() !== 'provider') {
            throw ApiException::forbidden('SEC-BR-05', 'La extranet está disponible solo para usuarios de proveedores. El personal interno gestiona proveedores desde Catálogo › Proveedores.');
        }
        $this->access->provider($user);
        $permission ??= 'supplier.portal.view';
        if (! $this->access->allows($user, $permission)) {
            throw ApiException::forbidden('SEC-BR-03', 'Su perfil en el proveedor no permite esta acción', [$permission]);
        }

        return $next($request);
    }
}
