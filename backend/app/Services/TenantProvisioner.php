<?php

namespace App\Services;

use App\Models\Tenant;
use App\Security\PermissionCatalog;
use App\Services\Bookings\BookingProvisioner;
use App\Services\Catalog\CatalogProvisioner;
use App\Services\Crm\CrmProvisioner;
use App\Services\Ops\OpsProvisioner;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Alta técnica de un tenant (PROC-001, detalle de negocio en 09): sede principal, copia de los
 * 12 roles plantilla con su matriz (editable por tenant) y correlativos por defecto.
 * Corre con la conexión de administración porque crea filas de un tenant aún sin contexto.
 */
class TenantProvisioner
{
    public const CONNECTION = 'pgsql_admin';

    /**
     * @param  array{slug: string, legal_name: string, ruc?: string, trade_name?: string, plan?: string, settings?: array}  $data
     */
    public function provision(array $data, string $mainSedeName = 'Sede principal'): Tenant
    {
        $db = DB::connection(self::CONNECTION);

        $tenantId = $db->transaction(function (ConnectionInterface $db) use ($data, $mainSedeName) {
            $tenantId = (string) Str::uuid7();
            $db->table('tenants')->insert([
                'id' => $tenantId,
                'slug' => $data['slug'],
                'legal_name' => $data['legal_name'],
                'trade_name' => $data['trade_name'] ?? null,
                'ruc' => $data['ruc'] ?? null,
                'plan' => $data['plan'] ?? 'estandar',
                'settings' => json_encode($data['settings'] ?? (object) []),
                'status' => 'activo',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $db->table('iam_sedes')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'code' => 'S01',
                'name' => $mainSedeName,
                'is_main' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->copyRoleTemplates($db, $tenantId);
            SequenceService::seedFor($tenantId, self::CONNECTION);
            CrmProvisioner::seedTenant($tenantId, $db);
            CatalogProvisioner::seedTenant($tenantId, $db);
            BookingProvisioner::seedTenant($tenantId, $db);
            OpsProvisioner::seedTenant($tenantId, $db);

            return $tenantId;
        });

        return Tenant::findOrFail($tenantId);
    }

    private function copyRoleTemplates(ConnectionInterface $db, string $tenantId): void
    {
        $templates = $db->table('roles')->whereNull('tenant_id')->where('guard_name', PermissionCatalog::GUARD)->get();

        foreach ($templates as $template) {
            $roleId = $db->table('roles')->insertGetId([
                'tenant_id' => $tenantId,
                'name' => $template->name,
                'guard_name' => $template->guard_name,
                'display_name' => $template->display_name,
                'description' => $template->description,
                'scope' => $template->scope,
                'is_custom' => false,
                'experience' => $template->experience,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $db->statement(
                'INSERT INTO role_has_permissions (permission_id, role_id) SELECT permission_id, ? FROM role_has_permissions WHERE role_id = ?',
                [$roleId, $template->id],
            );
        }
    }
}
