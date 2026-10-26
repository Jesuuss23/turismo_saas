<?php

namespace App\Services\Catalog;

use App\Models\Iam\User;
use Illuminate\Support\Collection;

/**
 * Destinatarios de notificaciones del catálogo por rol (§8.1, §12-25): el prototipo usa usuarios
 * fijos (U02, U03, U04, U05); aquí se resuelven todos los usuarios activos del rol.
 */
class CatalogRecipients
{
    /** @return Collection<int, User> */
    public static function role(string|array $roles): Collection
    {
        return User::role($roles)->where('status', User::ACTIVE)->orderBy('name')->get();
    }

    public static function managers(): Collection
    {
        return self::role('manager');
    }

    public static function sales(): Collection
    {
        return self::role('sales');
    }

    public static function operations(): Collection
    {
        return self::role('operations');
    }
}
