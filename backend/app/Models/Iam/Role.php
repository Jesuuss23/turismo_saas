<?php

namespace App\Models\Iam;

use App\Security\RoleUx;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Rol por tenant (tenant_id = team). tenant_id NULL = plantilla de sistema que se copia en el alta.
 */
class Role extends SpatieRole
{
    protected function casts(): array
    {
        return ['is_custom' => 'boolean'];
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
