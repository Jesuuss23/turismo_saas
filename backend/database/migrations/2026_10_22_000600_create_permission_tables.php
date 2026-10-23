<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // spatie/laravel-permission con teams (§3.5): team = tenant_id, modelos con id uuid.
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('guard_name', 20);
            $table->string('module', 40);
            $table->string('description', 200);
            $table->timestampsTz();
            $table->unique(['name', 'guard_name']);
        });
        // Catálogo global: la app solo lee.
        Rls::grantApp('permissions', ['SELECT']);

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants');
            $table->string('name', 60);
            $table->string('guard_name', 20);
            $table->string('display_name', 80);
            $table->string('description', 200)->nullable();
            $table->string('scope', 20);
            $table->boolean('is_custom')->default(false);
            $table->string('experience', 20);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'name', 'guard_name']);
            $table->index('tenant_id', 'roles_team_foreign_key_index');
        });
        DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_scope_check CHECK (scope IN ('tenant','mobile','canal'))");
        DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_experience_check CHECK (experience IN ('backoffice','portal','mobile'))");
        DB::statement('CREATE UNIQUE INDEX roles_template_name_unique ON roles (name, guard_name) WHERE tenant_id IS NULL');

        // Plantillas de sistema (tenant_id NULL) visibles en lectura; escritura solo en el propio tenant.
        $ctx = Rls::CONTEXT;
        Rls::enable('roles');
        DB::statement("CREATE POLICY tenant_isolation ON roles
            USING (tenant_id IS NULL OR tenant_id = {$ctx})
            WITH CHECK (tenant_id = {$ctx})");
        Rls::grantApp('roles', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
        Rls::grantSequence('roles_id_seq');

        // No se usa (§3.5: prohibidos permisos directos). Existe porque spatie la consulta;
        // la app solo tiene SELECT, de modo que nunca se puede escribir en ella.
        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->string('model_type');
            $table->uuid('model_id');
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->primary(['tenant_id', 'permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');
            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
        });
        Rls::tenantTable('model_has_permissions', ['SELECT']);

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('model_type');
            $table->uuid('model_id');
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->primary(['tenant_id', 'role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
        });
        // Un rol por usuario y tenant (§3.5).
        DB::statement('CREATE UNIQUE INDEX model_has_roles_one_role ON model_has_roles (tenant_id, model_type, model_id)');
        Rls::tenantTable('model_has_roles', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
        });
        // Sin tenant_id propio: hereda la visibilidad del rol (la subconsulta sobre roles ya pasa por RLS).
        Rls::enable('role_has_permissions');
        DB::statement("CREATE POLICY tenant_isolation ON role_has_permissions
            USING (EXISTS (SELECT 1 FROM roles r WHERE r.id = role_id))
            WITH CHECK (EXISTS (SELECT 1 FROM roles r WHERE r.id = role_id AND r.tenant_id = {$ctx}))");
        Rls::grantApp('role_has_permissions', ['SELECT', 'INSERT', 'DELETE']);
    }

    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
