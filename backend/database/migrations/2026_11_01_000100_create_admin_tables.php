<?php

use App\Services\Admin\AdminCatalog;
use App\Services\Admin\AdminProvisioner;
use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    // Spec 09 §3: Administración, reportes y panel ejecutivo. Plataforma (planes, entitlements, ciclo de vida del tenant),
    // identidad (sedes, invitaciones), configuración, notificaciones, integraciones, scheduler y exportaciones.
    public function up(): void
    {
        $app = config('turismo.db_roles.app');
        $platform = config('turismo.db_roles.platform');
        $ctx = Rls::CONTEXT;
        $checks = function (string $table, array $checks) {
            foreach ($checks as $name => $check) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_{$name}");
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$name} CHECK ({$check})");
            }
        };

        // §3.1–3.3 Catálogo de plataforma: la app y la consola solo leen.
        Schema::create('plans', function (Blueprint $table) {
            $table->string('id', 30)->primary();
            $table->string('name', 60);
            $table->smallInteger('rank');
            $table->decimal('price_monthly', 14, 2);
            $table->char('currency', 3)->default('PEN');
            $table->integer('max_users')->nullable();
            $table->integer('max_storage_gb');
            $table->integer('max_sedes')->nullable();
            $table->string('support_level', 80);
            $table->smallInteger('module_count');
            $table->string('status', 20)->default('activo');
            $table->timestampsTz();
        });
        $checks('plans', ['status_check' => "status IN ('activo','inactivo')"]);

        Schema::create('plan_features', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('feature_key', 60);
            $table->string('label', 120);
            $table->string('plan_id', 30);
            $table->foreign('plan_id')->references('id')->on('plans');
            $table->string('value', 30);
            $table->unique(['plan_id', 'feature_key']);
        });

        Schema::create('plan_channels', function (Blueprint $table) {
            $table->string('channel_key', 30)->primary();
            $table->string('label', 80);
            $table->string('min_plan_id', 30);
            $table->foreign('min_plan_id')->references('id')->on('plans');
            $table->string('description', 200);
            $table->string('depends_on_integration', 30)->nullable();
        });

        $now = now();
        $ranks = array_map(fn ($p) => $p[1], AdminCatalog::PLANS);
        foreach (AdminCatalog::PLANS as $id => [$name, $rank, $price, $users, $gb, $sedes, $support, $modules]) {
            DB::table('plans')->insert(['id' => $id, 'name' => $name, 'rank' => $rank, 'price_monthly' => $price, 'currency' => 'PEN', 'max_users' => $users,
                'max_storage_gb' => $gb, 'max_sedes' => $sedes, 'support_level' => $support, 'module_count' => $modules, 'status' => 'activo', 'created_at' => $now, 'updated_at' => $now]);
            foreach (AdminCatalog::FEATURES as $key => $f) {
                $included = $rank >= $ranks[$f[1]];
                DB::table('plan_features')->insert(['id' => (string) Str::uuid7(), 'feature_key' => $key, 'label' => $f[0], 'plan_id' => $id,
                    'value' => $included ? ($f[2][$id] ?? 'included') : 'not_included']);
            }
        }
        foreach (AdminCatalog::CHANNELS as $key => [$label, $min, $description, $integration]) {
            DB::table('plan_channels')->insert(['channel_key' => $key, 'label' => $label, 'min_plan_id' => $min, 'description' => $description, 'depends_on_integration' => $integration]);
        }
        foreach (['plans', 'plan_features', 'plan_channels'] as $table) {
            Rls::grantApp($table, ['SELECT']);
            Rls::grantPlatform($table, ['SELECT']);
        }

        // §3.4 Ciclo de vida del tenant (prueba → activo ⇄ suspendido), plan, consumo y datos del asistente.
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->unique();
            $table->string('fiscal_address', 250)->nullable();
            $table->string('business_type', 40)->nullable();
            $table->string('legal_rep', 150)->nullable();
            $table->string('custom_domain', 190)->nullable()->unique();
            $table->string('country', 40)->default('Perú');
            $table->char('alt_currency', 3)->default('USD');
            $table->string('locale', 10)->default('es-PE');
            $table->string('logo_initials', 3)->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->string('plan_id', 30)->default('professional');
            $table->foreign('plan_id')->references('id')->on('plans');
            $table->timestampTz('trial_ends_at')->nullable();
            $table->text('suspend_reason')->nullable();
            $table->decimal('mrr', 14, 2)->default(0);
            $table->smallInteger('modules')->default(12);
            $table->bigInteger('storage_used_bytes')->default(0);
            $table->string('admin_email', 190)->nullable();
            $table->uuid('created_by')->nullable();
            $table->integer('lock_version')->default(0);
            $table->index('status');
            $table->index('plan_id');
        });
        DB::statement('ALTER TABLE tenants DROP COLUMN plan');
        DB::statement('CREATE UNIQUE INDEX tenants_ruc_unique ON tenants (ruc) WHERE ruc IS NOT NULL');
        $checks('tenants', [
            'status_check' => "status IN ('prueba','activo','suspendido')",
            'ruc_check' => "ruc IS NULL OR ruc ~ '^(10|15|17|20)[0-9]{9}$'",
            'slug_check' => "slug ~ '^[a-z0-9-]{3,30}$'",
            'suspend_reason_check' => "status <> 'suspendido' OR suspend_reason IS NOT NULL",
        ]);
        // La app lee todos los tenants (resolver por slug y estado) y solo actualiza el suyo, en las columnas de Configuración (CFG-BR-03).
        DB::statement('ALTER TABLE tenants ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY app_read ON tenants FOR SELECT TO {$app} USING (true)");
        DB::statement("CREATE POLICY app_update ON tenants FOR UPDATE TO {$app} USING (id = {$ctx}) WITH CHECK (id = {$ctx})");
        DB::statement("CREATE POLICY platform_all ON tenants TO {$platform} USING (true) WITH CHECK (true)");
        DB::statement("GRANT UPDATE (legal_name, trade_name, logo_initials, timezone, locale, currency, alt_currency, fx_rate, igv_pct, settings, legal_rep, fiscal_address, updated_at) ON tenants TO {$app}");

        // TEN-BR-07: tenant desde el que opera la cuenta de plataforma (no puede suspenderse a sí mismo desde esa sesión).
        Schema::table('platform_users', fn (Blueprint $table) => $table->foreignUuid('home_tenant_id')->nullable()->constrained('tenants'));

        // §3.5 Snapshot del asistente PROC-001.
        Schema::create('tenant_onboarding', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants');
            $table->jsonb('payload');
            $table->string('permission_template', 40);
            $table->foreignUuid('copied_from_tenant_id')->nullable()->constrained('tenants');
            $table->string('idempotency_key', 80)->unique();
            $table->timestampTz('created_at');
        });
        $checks('tenant_onboarding', ['template_check' => "permission_template IN ('estandar','minimo_privilegio','copiar_tenant')"]);
        Rls::grantPlatform('tenant_onboarding', ['SELECT']);

        // §3.6 Sedes: dirección, responsable, estado y una sola principal.
        Schema::table('iam_sedes', function (Blueprint $table) {
            $table->string('address', 250)->nullable();
            $table->string('phone', 30)->nullable();
            $table->foreignUuid('manager_user_id')->nullable()->constrained('iam_users');
            $table->string('status', 20)->default('activa');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->integer('lock_version')->default(0);
        });
        DB::statement('CREATE UNIQUE INDEX iam_sedes_tenant_name_unique ON iam_sedes (tenant_id, lower(name)) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX iam_sedes_one_main ON iam_sedes (tenant_id) WHERE is_main AND deleted_at IS NULL');
        $checks('iam_sedes', ['status_check' => "status IN ('activa','inactiva')"]);
        Rls::grantApp('iam_sedes', ['DELETE']);

        // Edición concurrente de usuarios (PATCH /iam/users/{id} con lock_version, §6.2).
        Schema::table('iam_users', fn (Blueprint $table) => $table->integer('lock_version')->default(0));

        // §3.7 Invitaciones de un solo uso (72 h).
        Schema::create('iam_user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('user_id')->constrained('iam_users');
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->boolean('require_mfa')->default(true);
            $table->foreignUuid('invited_by')->nullable()->constrained('iam_users');
            $table->smallInteger('resent_count')->default(0);
            $table->timestampTz('created_at');
            $table->index(['tenant_id', 'user_id']);
        });
        Rls::tenantTable('iam_user_invitations');
        // Aceptar una invitación es público: el token resuelve tenant y usuario sin contexto (como el restablecimiento de contraseña).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION iam_invitation_lookup(p_hash char(64)) RETURNS TABLE (tenant_id uuid, user_id uuid)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
                SELECT tenant_id, user_id FROM iam_user_invitations WHERE token_hash = p_hash
            $$
        SQL);
        DB::statement('REVOKE ALL ON FUNCTION iam_invitation_lookup(char) FROM PUBLIC');
        DB::statement("GRANT EXECUTE ON FUNCTION iam_invitation_lookup(char) TO {$app}");

        // Roles personalizados: nombre visible único por tenant (RBAC-BR-06).
        DB::statement('CREATE UNIQUE INDEX roles_tenant_display_unique ON roles (tenant_id, lower(display_name)) WHERE tenant_id IS NOT NULL');

        // §3.8 Configuración por sección.
        Schema::create('sys_settings', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('section', 30);
            $table->jsonb('values');
            $table->uuid('updated_by')->nullable();
            $table->timestampTz('updated_at');
            $table->integer('lock_version')->default(0);
            $table->primary(['tenant_id', 'section']);
        });
        $checks('sys_settings', ['section_check' => "section IN ('general','currency','policies','operations','channels','notifications')"]);
        Rls::tenantTable('sys_settings');
        // CFG-BR-08: se agregan series nuevas.
        Rls::grantApp('sys_sequences', ['INSERT']);

        // §3.10–3.11 Reglas por evento y plantillas versionadas (tenant_id NULL = plantilla del sistema).
        Schema::create('notif_event_rules', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('event_code', 50);
            $table->string('label', 80);
            $table->boolean('ch_email')->default(false);
            $table->boolean('ch_whatsapp')->default(false);
            $table->boolean('ch_push')->default(false);
            $table->boolean('ch_portal')->default(false);
            $table->string('recipients', 200);
            $table->string('timing', 20)->default('immediate');
            $table->string('condition', 30)->default('always');
            $table->uuid('updated_by')->nullable();
            $table->timestampTz('updated_at');
            $table->unique(['tenant_id', 'event_code']);
        });
        $checks('notif_event_rules', [
            'timing_check' => "timing IN ('immediate','batch_15m','business_hours')",
            'condition_check' => "condition IN ('always','marketing_consent','amount_gt_5000')",
            'critical_check' => "event_code <> 'incidencia.critica' OR ch_email OR ch_whatsapp OR ch_push OR ch_portal",
        ]);
        Rls::tenantTable('notif_event_rules');

        Schema::create('notif_templates', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants');
            $table->string('event_code', 50);
            $table->string('channel', 20);
            $table->integer('version');
            $table->string('subject', 200);
            $table->text('body');
            $table->boolean('is_current')->default(true);
            $table->string('provider_template_id', 120)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at');
        });
        $checks('notif_templates', [
            'channel_check' => "channel IN ('email','whatsapp','push')",
            'version_check' => 'version >= 1',
            'push_check' => "channel <> 'push' OR char_length(body) <= 140",
        ]);
        DB::statement('CREATE UNIQUE INDEX notif_templates_current ON notif_templates (tenant_id, event_code, channel) WHERE is_current AND tenant_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX notif_templates_system ON notif_templates (event_code, channel) WHERE tenant_id IS NULL');
        Rls::enable('notif_templates');
        DB::statement("CREATE POLICY tenant_isolation ON notif_templates USING (tenant_id IS NULL OR tenant_id = {$ctx}) WITH CHECK (tenant_id = {$ctx})");
        Rls::grantApp('notif_templates', ['SELECT', 'INSERT', 'UPDATE']);
        foreach (AdminCatalog::TEMPLATES as $event => [$subject, $body]) {
            foreach (['email', 'whatsapp', 'push'] as $channel) {
                [$s, $b] = AdminCatalog::derive($channel, $subject, $body);
                DB::table('notif_templates')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => null, 'event_code' => $event, 'channel' => $channel,
                    'version' => 1, 'subject' => $s, 'body' => $b, 'is_current' => true, 'created_at' => $now]);
            }
        }

        // §3.12 Log de envíos: plantilla usada, canal alterno y mensaje del proveedor.
        Schema::table('notif_messages', function (Blueprint $table) {
            $table->foreignUuid('template_id')->nullable()->constrained('notif_templates');
            $table->boolean('is_fallback')->default(false);
            $table->foreignUuid('fallback_of_id')->nullable()->constrained('notif_messages');
            $table->string('provider_message_id', 120)->nullable();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });

        // §3.13 Adapters de integración (por tenant; la consola de plataforma ve la salud agregada).
        Schema::create('integration_adapters', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('kind', 30);
            $table->string('status', 20);
            $table->string('environment', 20)->default('sandbox');
            $table->foreignUuid('owner_user_id')->nullable()->constrained('iam_users');
            $table->smallInteger('max_retries')->default(5);
            $table->integer('backoff_base_s')->default(60);
            $table->integer('timeout_s')->default(30);
            $table->string('secret_ref', 200)->nullable();
            $table->timestampTz('secret_rotated_at')->nullable();
            $table->text('note')->nullable();
            $table->timestampTz('last_test_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_result', 250)->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'code']);
        });
        $checks('integration_adapters', [
            'kind_check' => "kind IN ('fiscal','payments','email','messaging','maps','suppliers','distribution')",
            'status_check' => "status IN ('activo','por_validar','sandbox','parcial','futuro')",
            'environment_check' => "environment IN ('sandbox','production')",
            'retries_check' => 'max_retries BETWEEN 0 AND 10',
            'backoff_check' => 'backoff_base_s >= 5',
            'timeout_check' => 'timeout_s BETWEEN 5 AND 120',
        ]);
        Rls::tenantTable('integration_adapters');

        // §3.14 Scheduler y ejecuciones.
        Schema::create('sys_scheduled_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('code', 20);
            $table->string('name', 150);
            $table->string('job_class', 150);
            $table->string('cron', 60);
            $table->string('cron_label', 60);
            $table->string('queue', 20);
            $table->boolean('paused')->default(false);
            $table->timestampTz('last_run_at')->nullable();
            $table->timestampTz('next_run_at')->nullable();
            $table->string('last_status', 20)->nullable();
            $table->string('last_result', 250)->nullable();
            $table->string('report_key', 60)->nullable();
            $table->string('report_format', 10)->nullable();
            $table->text('recipients')->nullable();
            $table->foreignUuid('owner_user_id')->nullable()->constrained('iam_users');
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'code']);
        });
        $checks('sys_scheduled_jobs', [
            'queue_check' => "queue IN ('critical','default','notifications','documents','integrations','reports','low')",
            'last_status_check' => "last_status IS NULL OR last_status IN ('ok','advertencia','error','en_curso')",
            'format_check' => "report_format IS NULL OR report_format IN ('PDF','XLSX','CSV')",
        ]);
        Rls::tenantTable('sys_scheduled_jobs', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);

        Schema::create('sys_job_runs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('scheduled_job_id')->constrained('sys_scheduled_jobs')->cascadeOnDelete();
            $table->string('trigger', 20);
            $table->uuid('triggered_by')->nullable();
            $table->string('status', 20);
            $table->string('result', 250)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->smallInteger('attempt')->default(1);
            $table->string('correlation_id', 40)->nullable();
            $table->index(['scheduled_job_id', 'started_at']);
        });
        $checks('sys_job_runs', [
            'trigger_check' => "trigger IN ('schedule','manual','retry')",
            'status_check' => "status IN ('en_curso','ok','advertencia','error')",
        ]);
        Rls::tenantTable('sys_job_runs');

        // §3.15 Errores de integración (jobLog del prototipo).
        Schema::create('integration_errors', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->bigIncrements('code');
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('source', 20);
            $table->string('ref_code', 30);
            $table->string('target', 150);
            $table->text('error');
            $table->string('error_class', 20);
            $table->smallInteger('attempt')->default(0);
            $table->smallInteger('max_attempts')->default(5);
            $table->timestampTz('next_retry_at')->nullable();
            $table->string('status', 20)->default('reintentando');
            $table->text('resolution')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status']);
        });
        DB::statement('ALTER TABLE integration_errors DROP CONSTRAINT integration_errors_pkey');
        DB::statement('ALTER TABLE integration_errors ADD PRIMARY KEY (id)');
        DB::statement('ALTER SEQUENCE integration_errors_code_seq RESTART WITH 410');
        $checks('integration_errors', [
            'source_check' => "source IN ('job','notification','integration')",
            'class_check' => "error_class IN ('transitorio','permanente')",
            'status_check' => "status IN ('reintentando','fallo_definitivo','resuelto')",
            'resolution_check' => "status <> 'resuelto' OR resolution IS NOT NULL",
        ]);
        Rls::tenantTable('integration_errors');
        Rls::grantSequence('integration_errors_code_seq');

        // §3.16 Exportaciones (PROC-061).
        Schema::create('report_exports', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->bigIncrements('code');
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('report_name', 200);
            $table->string('report_key', 40);
            $table->jsonb('filters')->default('{}');
            $table->string('scope', 20)->default('filtered');
            $table->string('format', 10);
            $table->integer('rows_count')->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->string('file_path', 255)->nullable();
            $table->string('status', 20)->default('en_cola');
            $table->foreignUuid('requested_by')->nullable()->constrained('iam_users');
            $table->foreignUuid('scheduled_job_id')->nullable()->constrained('sys_scheduled_jobs')->nullOnDelete();
            $table->string('idempotency_key', 80)->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at');
            $table->timestampTz('finished_at')->nullable();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'created_at']);
        });
        DB::statement('ALTER TABLE report_exports DROP CONSTRAINT report_exports_pkey');
        DB::statement('ALTER TABLE report_exports ADD PRIMARY KEY (id)');
        DB::statement('ALTER SEQUENCE report_exports_code_seq RESTART WITH 31');
        $checks('report_exports', [
            'scope_check' => "scope IN ('filtered','detailed')",
            'format_check' => "format IN ('CSV','XLSX','PDF')",
            'status_check' => "status IN ('en_cola','procesando','disponible','fallido','expirado')",
        ]);
        Rls::tenantTable('report_exports');
        Rls::grantSequence('report_exports_code_seq');

        // Consola de plataforma: métricas agregadas por tenant sin leer datos de negocio (spec 09 §11-20).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION platform_tenant_stats() RETURNS TABLE (
                tenant_id uuid, users integer, sedes integer, internal_users integer, mfa_missing integer, open_errors integer,
                definitive_errors integer, jobs_error integer, jobs_paused integer, integrations_ok integer, integrations_total integer, last_activity timestamptz
            ) LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
                SELECT t.id,
                    (SELECT count(*)::int FROM iam_users u WHERE u.tenant_id = t.id AND u.status <> 'inactivo' AND u.deleted_at IS NULL),
                    (SELECT count(*)::int FROM iam_sedes s WHERE s.tenant_id = t.id AND s.status = 'activa' AND s.deleted_at IS NULL),
                    (SELECT count(*)::int FROM iam_users u JOIN model_has_roles m ON m.model_id = u.id JOIN roles r ON r.id = m.role_id
                        WHERE u.tenant_id = t.id AND u.status = 'activo' AND r.scope = 'tenant'),
                    (SELECT count(*)::int FROM iam_users u JOIN model_has_roles m ON m.model_id = u.id JOIN roles r ON r.id = m.role_id
                        WHERE u.tenant_id = t.id AND u.status = 'activo' AND r.scope = 'tenant' AND NOT u.mfa_enabled),
                    (SELECT count(*)::int FROM integration_errors e WHERE e.tenant_id = t.id AND e.status <> 'resuelto'),
                    (SELECT count(*)::int FROM integration_errors e WHERE e.tenant_id = t.id AND e.status = 'fallo_definitivo'),
                    (SELECT count(*)::int FROM sys_scheduled_jobs j WHERE j.tenant_id = t.id AND j.last_status = 'error'),
                    (SELECT count(*)::int FROM sys_scheduled_jobs j WHERE j.tenant_id = t.id AND j.paused),
                    (SELECT count(*)::int FROM integration_adapters a WHERE a.tenant_id = t.id AND a.status = 'activo'),
                    (SELECT count(*)::int FROM integration_adapters a WHERE a.tenant_id = t.id),
                    (SELECT max(u.last_login_at) FROM iam_users u WHERE u.tenant_id = t.id)
                FROM tenants t
            $$
        SQL);
        DB::statement('REVOKE ALL ON FUNCTION platform_tenant_stats() FROM PUBLIC');
        DB::statement("GRANT EXECUTE ON FUNCTION platform_tenant_stats() TO {$platform}");

        // Tenants existentes: datos de plataforma y semillas de Administración.
        $db = DB::connection();
        $n = 0;
        foreach ($db->table('tenants')->orderBy('created_at')->get() as $tenant) {
            $n++;
            $db->table('tenants')->where('id', $tenant->id)->update(['code' => sprintf('TNT-%04d', $n), 'status' => $tenant->status,
                'logo_initials' => Str::upper(Str::substr((string) ($tenant->trade_name ?? $tenant->slug), 0, 2))]);
            AdminProvisioner::seedTenant($tenant->id, $db);
        }
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS platform_tenant_stats()');
        DB::statement('DROP FUNCTION IF EXISTS iam_invitation_lookup(char)');
        foreach (['report_exports', 'integration_errors', 'sys_job_runs', 'sys_scheduled_jobs', 'integration_adapters'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('notif_messages', fn (Blueprint $t) => $t->dropConstrainedForeignId('template_id'));
        Schema::table('platform_users', fn (Blueprint $t) => $t->dropConstrainedForeignId('home_tenant_id'));
        foreach (['notif_templates', 'notif_event_rules', 'sys_settings', 'iam_user_invitations', 'tenant_onboarding', 'plan_channels', 'plan_features'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
