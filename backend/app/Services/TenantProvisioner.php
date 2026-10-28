<?php

namespace App\Services;

use App\Domain\Bookings\BookingRulesProvisioner;
use App\Models\Tenant;
use App\Security\PermissionCatalog;
use App\Services\Admin\AdminCatalog;
use App\Services\Admin\AdminProvisioner;
use App\Services\Bookings\BookingProvisioner;
use App\Services\Catalog\CatalogProvisioner;
use App\Services\Channels\ChannelProvisioner;
use App\Services\Collab\CollabProvisioner;
use App\Services\Crm\CrmProvisioner;
use App\Services\Experience\ExperienceProvisioner;
use App\Services\Experience\OwnReviewProvisioner;
use App\Services\Finance\FinanceProvisioner;
use App\Services\Inventory\InventoryProvisioner;
use App\Services\Marketing\MarketingProvisioner;
use App\Services\Ops\OpsProvisioner;
use App\Services\Platform\PlatformProvisioner;
use App\Services\Site\SiteProvisioner;
use App\Services\Supplier\SupplierProvisioner;
use App\Services\Treasury\TreasuryProvisioner;
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
     * @param  array{slug: string, legal_name: string, ruc?: string, trade_name?: string, plan_id?: string, status?: string, settings?: array}  $data
     * @param  array{roles?: list<string>, template?: string, copy_from?: string|null, sede?: array, overrides?: array}  $options  asistente PROC-001 (spec 09 §6.1)
     */
    public function provision(array $data, string $mainSedeName = 'Sede principal', array $options = []): Tenant
    {
        $db = DB::connection(self::CONNECTION);

        $tenantId = $db->transaction(function (ConnectionInterface $db) use ($data, $mainSedeName, $options) {
            $tenantId = (string) Str::uuid7();
            $plan = $data['plan_id'] ?? 'professional';
            $status = $data['status'] ?? 'activo';
            // Correlativo de plataforma TNT-0001 (bloqueo de tabla corto: el alta es poco frecuente).
            $db->statement('LOCK TABLE tenants IN SHARE ROW EXCLUSIVE MODE');
            $last = (int) $db->table('tenants')->selectRaw('max(substring(code from 5)::int) as n')->value('n');
            $db->table('tenants')->insert([
                'id' => $tenantId,
                'code' => sprintf('TNT-%04d', $last + 1),
                'slug' => $data['slug'],
                'legal_name' => $data['legal_name'],
                'trade_name' => $data['trade_name'] ?? null,
                'ruc' => $data['ruc'] ?? null,
                'plan_id' => $plan,
                'settings' => json_encode($data['settings'] ?? (object) []),
                'status' => $status,
                'trial_ends_at' => $status === 'prueba' ? now()->addDays(14) : null,
                'mrr' => $status === 'activo' ? AdminCatalog::PLANS[$plan][2] : 0,
                'modules' => AdminCatalog::PLANS[$plan][7],
                'logo_initials' => $data['logo_initials'] ?? Str::upper(Str::substr((string) ($data['trade_name'] ?? $data['slug']), 0, 2)),
                ...array_intersect_key($data, array_flip(['fiscal_address', 'business_type', 'legal_rep', 'country', 'currency', 'alt_currency', 'timezone',
                    'locale', 'igv_pct', 'admin_email', 'created_by', 'custom_domain'])),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $db->table('iam_sedes')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'code' => 'S01',
                'name' => $options['sede']['name'] ?? $mainSedeName,
                'city' => $options['sede']['city'] ?? null,
                'address' => $options['sede']['address'] ?? null,
                'is_main' => true,
                'status' => 'activa',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->copyRoleTemplates($db, $tenantId, $options['roles'] ?? null, $options['template'] ?? 'estandar', $options['copy_from'] ?? null);
            SequenceService::seedFor($tenantId, self::CONNECTION);
            CrmProvisioner::seedTenant($tenantId, $db);
            CatalogProvisioner::seedTenant($tenantId, $db);
            BookingProvisioner::seedTenant($tenantId, $db);
            OpsProvisioner::seedTenant($tenantId, $db);
            SupplierProvisioner::seedTenant($tenantId, $db);
            FinanceProvisioner::seedTenant($tenantId, $db);
            ChannelProvisioner::seedTenant($tenantId, $db);
            CollabProvisioner::seedTenant($tenantId, $db);
            InventoryProvisioner::seedTenant($tenantId, $db);
            TreasuryProvisioner::seedTenant($tenantId, $db);
            MarketingProvisioner::seedTenant($tenantId, $db);
            SiteProvisioner::seedTenant($tenantId, $db);
            BookingRulesProvisioner::seedTenant($tenantId, $db);
            ExperienceProvisioner::seedTenant($tenantId, $db);
            OwnReviewProvisioner::seedTenant($tenantId, $db);
            AdminProvisioner::seedTenant($tenantId, $db, $options['overrides'] ?? []);
            PlatformProvisioner::seedTenant($tenantId, $db);
            PlatformProvisioner::subscribe($db->table('tenants')->where('id', $tenantId)->first(), $db);

            return $tenantId;
        });

        return Tenant::findOrFail($tenantId);
    }

    /**
     * Roles elegidos en el asistente (Tenant Admin siempre, TEN-BR-04) con su matriz: “Estándar” copia la plantilla,
     * “Mínimo privilegio” deja a los no administradores solo lectura y operación básica (TEN-BR-12) y “Copiar tenant” toma la matriz editada de otro tenant.
     *
     * @param  list<string>|null  $roles
     */
    private function copyRoleTemplates(ConnectionInterface $db, string $tenantId, ?array $roles = null, string $template = 'estandar', ?string $copyFrom = null): void
    {
        $templates = $db->table('roles')->whereNull('tenant_id')->where('guard_name', PermissionCatalog::GUARD)
            ->when($roles !== null, fn ($q) => $q->whereIn('name', array_unique([...$roles, 'tenant_admin'])))->get();

        foreach ($templates as $role) {
            $source = $template === 'copiar_tenant' && $copyFrom !== null
                ? ($db->table('roles')->where('tenant_id', $copyFrom)->where('name', $role->name)->value('id') ?? $role->id)
                : $role->id;
            $roleId = $db->table('roles')->insertGetId([
                'tenant_id' => $tenantId,
                'name' => $role->name,
                'guard_name' => $role->guard_name,
                'display_name' => $role->display_name,
                'description' => $role->description,
                'scope' => $role->scope,
                'is_custom' => false,
                'experience' => $role->experience,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $minimal = $template === 'minimo_privilegio' && $role->name !== 'tenant_admin';
            $db->statement(
                'INSERT INTO role_has_permissions (permission_id, role_id) SELECT rp.permission_id, ? FROM role_has_permissions rp JOIN permissions p ON p.id = rp.permission_id
                 WHERE rp.role_id = ?'.($minimal ? " AND (p.name LIKE '%.view' OR p.name LIKE '%.receivables' OR p.name LIKE '%.manage_availability' OR p.name LIKE '%.create')" : ''),
                [$roleId, $source],
            );
        }
    }
}
