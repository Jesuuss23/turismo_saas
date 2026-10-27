<?php

namespace App\Services\Ops;

use App\Exceptions\ApiException;
use App\Models\Iam\User;
use App\Models\Ops\Driver;
use App\Models\Ops\Guide;
use Illuminate\Database\Eloquent\Model;

/**
 * Permisos del módulo (§7): Operaciones ve costos de proveedor pero no venta ni margen; guía y conductor solo operan
 * sus asignaciones (OPS-BR-52); rooming exige `reservations.modify` (§11-10).
 */
class OpsAccess
{
    public const VIEW = 'operations.view';

    public const ASSIGN = 'operations.assign';

    public const VOUCHERS = 'operations.vouchers';

    public const INCIDENTS = 'operations.incidents';

    public const CLOSE = 'operations.close';

    public const ROOMING = 'reservations.modify';

    public const COORDINATOR_ROLES = ['operations', 'manager', 'tenant_admin'];

    /** Venta y margen en Cierre / cost-summary (§7). */
    public function seeMargin(User $user): bool
    {
        return $user->hasAnyPermission(['finance.profitability', 'catalog.products.view_profitability', 'quotes.approve_internal']);
    }

    public function can(User $user, string $permission): bool
    {
        return $user->hasPermissionTo($permission);
    }

    public function reason(User $user, string $permission): ?string
    {
        return $this->can($user, $permission) ? null : "Requiere permiso {$permission}";
    }

    /**
     * Recurso operativo del usuario (guía o conductor). Un interno con `operations.assign` puede "ver como recurso"
     * (vista previa §2, `as` = código G01/C01). Sin vínculo: MOB-BR-13.
     *
     * @return array{0: string, 1: Guide|Driver}
     */
    public function resource(User $user, ?string $as = null): array
    {
        if (! $user->isExternal() && $user->hasPermissionTo(self::ASSIGN)) {
            $resource = $as ? (Guide::where('code', $as)->first() ?? Driver::where('code', $as)->first())
                : (Guide::where('user_id', $user->id)->first() ?? Driver::where('user_id', $user->id)->first() ?? Guide::orderBy('code')->first());
            if (! $resource) {
                throw ApiException::unprocessable('MOB-BR-13', 'No hay guías ni conductores para previsualizar ("Ver como recurso").');
            }

            return [$resource instanceof Guide ? 'guide' : 'driver', $resource];
        }
        $guide = Guide::where('user_id', $user->id)->orWhere('id', $user->guide_id)->first();
        $driver = $guide ? null : Driver::where('user_id', $user->id)->orWhere('id', $user->driver_id)->first();
        if (! $guide && ! $driver) {
            throw ApiException::forbidden('MOB-BR-13', 'Su usuario no está vinculado a un recurso operativo. Pida a Operaciones que lo vincule.');
        }

        return $guide ? ['guide', $guide] : ['driver', $driver];
    }

    public static function column(string $type): string
    {
        return $type === 'guide' ? 'guide_id' : 'driver_id';
    }

    /** Usuario que recibe notificaciones del recurso (vínculo `user_id` o `users.guide_id/driver_id`). */
    public static function userOf(?Model $resource): ?User
    {
        if (! $resource) {
            return null;
        }
        $column = $resource instanceof Guide ? 'guide_id' : ($resource instanceof Driver ? 'driver_id' : null);

        return $resource->user_id ? User::find($resource->user_id) : ($column ? User::where($column, $resource->id)->first() : null);
    }
}
