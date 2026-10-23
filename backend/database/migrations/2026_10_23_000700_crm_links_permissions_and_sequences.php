<?php

use App\Services\Crm\CrmProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spec 01 §3.12 / §11-27: la pestaña Comunicaciones del 360 filtra por FK, no por texto.
        Schema::table('notif_messages', function (Blueprint $table) {
            $table->foreignUuid('client_id')->nullable()->constrained('crm_clients');
            $table->string('related_type', 20)->nullable();
            $table->uuid('related_id')->nullable();
            $table->index(['tenant_id', 'client_id']);
        });

        // Spec 01 §11-24: permiso explícito de reasignación para Tenant Admin y Manager en los tenants existentes.
        // El catálogo y las plantillas los sincroniza PermissionCatalogSeeder.
        $permission = DB::table('permissions')->where('name', 'crm.records.reassign')->where('guard_name', 'api')->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => 'crm.records.reassign', 'guard_name' => 'api', 'module' => 'CRM',
                'description' => 'Asignar y reasignar leads, oportunidades, actividades y clientes entre asesores',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        $roles = DB::table('roles')->whereIn('name', ['tenant_admin', 'manager'])->pluck('id');
        foreach ($roles as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permission, 'role_id' => $roleId]);
        }

        // Catálogos, etapas y correlativos CRM para los tenants ya creados.
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            CrmProvisioner::seedTenant($tenantId, DB::connection());
        }
    }

    public function down(): void
    {
        Schema::table('notif_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn(['related_type', 'related_id']);
        });
    }
};
