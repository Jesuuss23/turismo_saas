<?php

namespace App\Services\Supplier;

use App\Exceptions\ApiException;
use App\Models\Catalog\Provider;
use App\Models\Iam\User;

/**
 * Permisos de la extranet (spec 06 §7): el rol Proveedor trae los `supplier.*` y el `provider_role` del usuario los acota
 * (MVP: `admin` con todos). El proveedor se resuelve siempre del usuario autenticado, nunca del request (SUP-BR-01).
 */
class SupplierAccess
{
    public const ROLES = ['admin' => 'Administrador', 'reservas' => 'Reservas', 'recepcion' => 'Recepción', 'contabilidad' => 'Contabilidad'];

    /** provider_role => permisos (§7). */
    public const MATRIX = [
        'admin' => ['supplier.portal.view', 'supplier.requests.respond', 'supplier.rooming.manage', 'supplier.vouchers.redeem', 'supplier.payables.view',
            'supplier.invoices.upload', 'supplier.incidents.report', 'supplier.rates.request', 'supplier.users.manage'],
        'reservas' => ['supplier.portal.view', 'supplier.requests.respond', 'supplier.rooming.manage', 'supplier.vouchers.redeem', 'supplier.incidents.report'],
        'recepcion' => ['supplier.portal.view', 'supplier.rooming.manage', 'supplier.vouchers.redeem', 'supplier.incidents.report'],
        'contabilidad' => ['supplier.portal.view', 'supplier.payables.view', 'supplier.invoices.upload'],
    ];

    public const NOT_LINKED = 'Su usuario no está vinculado a un proveedor. Pida a la agencia que lo vincule.';

    public function allows(User $user, string $permission): bool
    {
        return $user->hasPermissionTo($permission) && in_array($permission, self::MATRIX[$user->provider_role ?? 'admin'] ?? [], true);
    }

    /** @return list<string> */
    public function permissions(User $user): array
    {
        return array_values(array_filter(self::MATRIX['admin'], fn (string $p) => $this->allows($user, $p)));
    }

    public function provider(User $user): Provider
    {
        return ($user->provider_id ? Provider::withTrashed()->find($user->provider_id) : null) ?? throw ApiException::forbidden('SUP-BR-01', self::NOT_LINKED);
    }
}
