<?php

namespace Database\Seeders;

use App\Security\PermissionCatalog;
use App\Security\RoleCatalog;
use App\Services\TenantProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Catálogo de 57 permisos (§7.1) y 12 roles plantilla de tenant con la matriz “Estándar” (§7.3).
 * Idempotente: se puede volver a ejecutar para sincronizar plantillas. No toca los roles ya
 * copiados a cada tenant (su matriz es editable por el Tenant Admin).
 */
class PermissionCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // Catálogo global: siempre con la conexión de administración (la app solo tiene SELECT).
        $db = DB::connection(TenantProvisioner::CONNECTION);
        $now = now();

        foreach (PermissionCatalog::GROUPS as $module => $permissions) {
            foreach ($permissions as $name => $description) {
                $db->table('permissions')->upsert(
                    ['name' => $name, 'guard_name' => PermissionCatalog::GUARD, 'module' => $module, 'description' => $description, 'created_at' => $now, 'updated_at' => $now],
                    ['name', 'guard_name'],
                    ['module', 'description', 'updated_at'],
                );
            }
        }

        $permissionIds = $db->table('permissions')->where('guard_name', PermissionCatalog::GUARD)->pluck('id', 'name');
        $matrix = RoleCatalog::matrix();

        foreach (RoleCatalog::tenantRoles() as $name => $meta) {
            $role = $db->table('roles')->whereNull('tenant_id')->where('name', $name)->where('guard_name', PermissionCatalog::GUARD)->first();
            $values = $meta + ['is_custom' => false, 'updated_at' => $now];

            if ($role) {
                $db->table('roles')->where('id', $role->id)->update($values);
                $roleId = $role->id;
            } else {
                $roleId = $db->table('roles')->insertGetId($values + ['name' => $name, 'guard_name' => PermissionCatalog::GUARD, 'tenant_id' => null, 'created_at' => $now]);
            }

            $db->table('role_has_permissions')->where('role_id', $roleId)->delete();
            $db->table('role_has_permissions')->insert(array_map(
                fn (string $permission) => ['role_id' => $roleId, 'permission_id' => $permissionIds[$permission]],
                $matrix[$name],
            ));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
