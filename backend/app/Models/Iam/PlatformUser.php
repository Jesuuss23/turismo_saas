<?php

namespace App\Models\Iam;

use App\Security\AccessSubject;
use App\Security\RoleCatalog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Super Admin SaaS (§3.1). Conexión propia (platform_role) y permisos fijos: no opera datos de tenants.
 */
class PlatformUser extends Authenticatable
{
    use HasApiTokens, HasUuids;

    protected $connection = 'pgsql_platform';

    protected $guarded = ['id'];

    protected $hidden = ['password', 'mfa_secret'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'mfa_secret' => 'encrypted',
            'last_login_at' => 'datetime',
        ];
    }

    public function roleName(): string
    {
        return RoleCatalog::SUPER_ADMIN;
    }

    public function accessSubject(): AccessSubject
    {
        return new AccessSubject(role: RoleCatalog::SUPER_ADMIN, permissions: $this->permissionNames(), roleScope: 'platform', roleLabel: 'Super Admin SaaS');
    }

    public function hasPermissionTo(string $permission): bool
    {
        return in_array($permission, $this->permissionNames(), true);
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        return RoleCatalog::SUPER_ADMIN_PERMISSIONS;
    }
}
