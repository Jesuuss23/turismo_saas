<?php

namespace App\Models\Iam;

use App\Security\RoleUx;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rol por tenant (tenant_id = team). tenant_id NULL = plantilla de sistema que se copia en el alta.
 */
class Role extends SpatieRole
{
    protected function casts(): array
    {
        return ['is_custom' => 'boolean'];
    }

    /**
     * Resuelve por nombre el rol del tenant antes que la plantilla homónima (spatie toma el primero de
     * `tenant_id = equipo OR tenant_id IS NULL`): así la matriz editada por el tenant es la que aplica (spec 09 §6.3).
     */
    protected static function findByParam(array $params = []): ?RoleContract
    {
        $registrar = app(PermissionRegistrar::class);
        $query = static::query();
        $team = $params[$registrar->teamsKey] ?? getPermissionsTeamId();
        unset($params[$registrar->teamsKey]);
        $query->where(fn ($q) => $q->whereNull($registrar->teamsKey)->orWhere($registrar->teamsKey, $team));
        foreach ($params as $key => $value) {
            $query->where($key, $value);
        }

        return $query->orderByRaw("{$registrar->teamsKey} IS NULL")->first();
    }

    public function isTemplate(): bool
    {
        return $this->tenant_id === null;
    }

    public function ux(?string $clientId = null): array
    {
        return RoleUx::for($this->name, $this->scope, $clientId);
    }
}
