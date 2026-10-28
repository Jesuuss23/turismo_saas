<?php

use App\Domain\Bookings\RuleCatalog;
use App\Services\Experience\ExperienceProvisioner;
use App\Services\Platform\PlatformCatalog;
use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const TABLES = [
        'notif_pax_message_templates', 'notif_pax_messages', 'fin_currencies', 'fx_rate_providers', 'integration_review_sources',
        'channel_external_reviews', 'channel_widget_config', 'channel_widget_domains', 'channel_widget_handoffs',
    ];

    private const SECTIONS = "'general','currency','policies','operations','channels','notifications','travel_docs'";

    // Spec 17 §3: centro de reglas v2.2 (plan mínimo, plantillas por tipo de negocio), mensajes al pasajero, monedas y tipos de cambio,
    // reseñas externas e integración web (widget). Las listas cortas van en jsonb, como en la spec 16.
    public function up(): void
    {
        $base = function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
        };
        $audit = function (Blueprint $table) {
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
        };
        $checks = function (string $table, array $checks) {
            foreach ($checks as $name => $check) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$name} CHECK ({$check})");
            }
        };

        // §3.1 v2.2: la matriz Planes × reglas de la spec 13 pasa a los códigos R-* del motor (32 reglas, DEMO 15/14/3).
        $now = now();
        $rules = PlatformCatalog::bookingRules();
        DB::table('plan_rule_entitlements')->whereNotIn('rule_code', array_keys($rules))->delete();
        DB::table('booking_rule_catalog')->whereNotIn('code', array_keys($rules))->delete();
        $i = 0;
        foreach ($rules as $code => [$group, $label, $min]) {
            DB::table('booking_rule_catalog')->upsert([['code' => $code, 'group_key' => $group, 'label' => $label, 'sort' => ++$i]], ['code'], ['group_key', 'label', 'sort']);
            DB::table('plan_rule_entitlements')->insertOrIgnore(['rule_code' => $code, 'min_plan' => $min, 'is_demo' => true, 'updated_at' => $now]);
        }
        // Nueva regla R-OWN-REVIEWS y parámetro daysAfter de R-REVIEW-INVITE en el catálogo y en cada tenant.
        Schema::table('sys_booking_rules', fn (Blueprint $table) => $table->string('depends_on', 40)->nullable());
        foreach (RuleCatalog::templates() as $t) {
            DB::table('platform_booking_rule_templates')->upsert([$t], ['rule_key'], ['description', 'params', 'params_schema', 'sort_order']);
        }
        DB::table('sys_booking_rules')->where('rule_key', 'R-REVIEW-INVITE')->whereRaw("params = '{}'::jsonb")
            ->update(['params' => json_encode(['daysAfter' => 2]), 'params_schema' => json_encode(RuleCatalog::schema(['daysAfter' => 2], 'R-REVIEW-INVITE'))]);
        DB::table('sys_booking_rules')->where('rule_key', 'R-DEPOSIT')->update(['params_schema' => json_encode(RuleCatalog::schema(RuleCatalog::RULES['R-DEPOSIT'][4], 'R-DEPOSIT'))]);
        DB::table('sys_booking_rules')->where('rule_key', 'R-MULTICURRENCY')->update(['params_schema' => json_encode(RuleCatalog::schema(RuleCatalog::RULES['R-MULTICURRENCY'][4], 'R-MULTICURRENCY'))]);
        foreach (RuleCatalog::DEPENDS_ON as $child => $parent) {
            DB::table('sys_booking_rules')->where('rule_key', $child)->update(['depends_on' => $parent]);
        }

        // §3.3 plantillas por tipo de negocio (plataforma).
        Schema::create('sys_rule_templates', function (Blueprint $table) {
            $table->string('code', 20)->primary();
            $table->string('name', 120);
            $table->text('description');
            $table->jsonb('enabled_codes');
        });
        foreach (RuleCatalog::BUSINESS_TEMPLATES as $code => [$name, $description, $enabled]) {
            DB::table('sys_rule_templates')->insert(['code' => $code, 'name' => $name, 'description' => $description, 'enabled_codes' => json_encode($enabled)]);
        }
        Rls::grantApp('sys_rule_templates', ['SELECT']);
        Rls::grantPlatform('sys_rule_templates', ['SELECT', 'INSERT', 'UPDATE']);

        DB::statement('ALTER TABLE sys_settings DROP CONSTRAINT IF EXISTS sys_settings_section_check');
        DB::statement('ALTER TABLE sys_settings ADD CONSTRAINT sys_settings_section_check CHECK (section IN ('.self::SECTIONS.",'ecommerce'))");

        // §3.4 plantillas de mensajes al pasajero.
        Schema::create('notif_pax_message_templates', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('event', 30);
            $table->integer('offset_hours')->default(0);
            $table->boolean('offset_from_rule')->default(false);
            $table->jsonb('channels');
            $table->string('subject', 200)->nullable();
            $table->text('body');
            $table->char('lang', 2)->default('ES');
            $table->string('group_code', 20)->nullable();
            $table->jsonb('conditions')->default('{}');
            $table->string('whatsapp_template_name', 120)->nullable();
            $table->boolean('active')->default(true);
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique(['tenant_id', 'code']);
        });
        $checks('notif_pax_message_templates', [
            'event_check' => "event IN ('reserva_confirmada','saldo_vence','viaje','fin_viaje','cambio_horario','cumpleanos')",
            'offset_check' => 'offset_hours BETWEEN -720 AND 720',
            'channels_check' => "jsonb_typeof(channels) = 'array' AND jsonb_array_length(channels) >= 1 AND channels <@ '[\"email\",\"whatsapp\",\"portal\",\"push\"]'::jsonb",
            'lang_check' => "lang IN ('ES','EN')",
        ]);

        // §3.5 mensajes (programados e historial).
        Schema::create('notif_pax_messages', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->foreignUuid('reservation_id')->nullable()->constrained('booking_reservations');
            $table->foreignUuid('template_id')->nullable()->constrained('notif_pax_message_templates');
            $table->string('channel', 10);
            $table->string('recipient', 200);
            $table->text('subject')->nullable();
            $table->text('body');
            $table->string('status', 15);
            $table->string('direction', 10)->default('saliente');
            $table->string('origin', 10);
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->string('provider_message_id', 120)->nullable();
            $table->text('error')->nullable();
            $table->char('lang', 2)->default('ES');
            $audit($table);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status', 'scheduled_at']);
            $table->index(['tenant_id', 'reservation_id']);
            $table->index('provider_message_id');
        });
        $checks('notif_pax_messages', [
            'channel_check' => "channel IN ('email','whatsapp','portal','push')",
            'status_check' => "status IN ('programado','enviado','entregado','leido','fallido','cancelado')",
            'direction_check' => "direction IN ('saliente','entrante')",
            'origin_check' => "origin IN ('auto','manual','prueba')",
        ]);
        DB::statement("CREATE UNIQUE INDEX notif_pax_messages_auto_unique ON notif_pax_messages (tenant_id, reservation_id, template_id, channel) WHERE origin = 'auto' AND status <> 'cancelado'");

        // §3.6 monedas, proveedores y ampliación del histórico de tipos de cambio (append-only, un valor vigente por día).
        Schema::create('fin_currencies', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->char('code', 3);
            $table->string('name', 60);
            $table->string('symbol', 8);
            $table->decimal('rate_pen', 14, 6);
            $table->boolean('active')->default(false);
            $table->boolean('is_base')->default(false);
            $table->boolean('is_charge')->default(false);
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique(['tenant_id', 'code']);
        });
        $checks('fin_currencies', ['charge_check' => 'NOT is_charge OR active', 'base_check' => 'NOT is_base OR (active AND is_charge)', 'rate_check' => 'rate_pen > 0']);
        DB::statement('CREATE UNIQUE INDEX fin_currencies_base_unique ON fin_currencies (tenant_id) WHERE is_base');

        Schema::create('fx_rate_providers', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('kind', 20);
            $table->string('status', 20);
            $table->string('schedule_cron', 40)->nullable();
            $table->string('secret_ref', 200)->nullable();
            $table->timestampTz('last_run_at')->nullable();
            $table->text('last_error')->nullable();
            $table->text('note')->nullable();
            $audit($table);
            $table->unique(['tenant_id', 'code']);
        });
        $checks('fx_rate_providers', ['kind_check' => "kind IN ('Simulado','Oficial','API','Manual')", 'status_check' => "status IN ('activo','por_validar','inactivo','error')"]);

        DB::statement('ALTER TABLE fin_fx_rates DROP CONSTRAINT IF EXISTS fin_fx_rates_source_check');
        DB::statement('ALTER TABLE fin_fx_rates ALTER COLUMN source TYPE varchar(80)');
        Schema::table('fin_fx_rates', function (Blueprint $table) {
            $table->decimal('previous_rate', 14, 6)->nullable();
            $table->uuid('provider_id')->nullable();
            $table->decimal('daily_change_pct', 7, 3)->nullable();
            $table->boolean('alert_raised')->default(false);
            $table->boolean('is_demo')->default(false);
            $table->timestampTz('effective_at')->nullable();
            $table->foreign('provider_id')->references('id')->on('fx_rate_providers');
            $table->index(['tenant_id', 'currency', 'rate_date']);
        });
        $checks('fin_fx_rates', ['sell_check' => 'sell > 0']);

        // La reserva guarda la moneda en que el cliente vio el precio (regla transversal 15); la contable sigue en `currency`.
        Schema::table('booking_reservations', fn (Blueprint $table) => $table->char('currency_display', 3)->nullable());

        // §3.7 reseñas externas (los secretos van cifrados; la API solo muestra los últimos 4 caracteres, RV-03).
        Schema::create('integration_review_sources', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('provider', 20);
            $table->string('status', 15)->default('no_conectado');
            $table->string('account_ref', 120)->nullable();
            $table->string('secret_ref', 200)->nullable();
            $table->text('secret_encrypted')->nullable();
            $table->char('secret_last4', 4)->nullable();
            $table->jsonb('scopes')->default('[]');
            $table->boolean('can_reply')->default(false);
            $table->boolean('supports_invites')->default(false);
            $table->decimal('rating', 3, 2)->nullable();
            $table->integer('review_count')->default(0);
            $table->timestampTz('last_sync_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('connected_at')->nullable();
            $table->uuid('connected_by')->nullable();
            $table->timestampTz('contract_ack_at')->nullable();
            $table->uuid('contract_ack_by')->nullable();
            $audit($table);
            $table->unique(['tenant_id', 'provider']);
        });
        $checks('integration_review_sources', [
            'provider_check' => "provider IN ('tripadvisor','trustpilot','google','facebook','getyourguide','viator')",
            'status_check' => "status IN ('no_conectado','pendiente','conectado','error')",
        ]);
        Schema::create('channel_external_reviews', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->foreignUuid('source_id')->constrained('integration_review_sources');
            $table->string('external_id', 120);
            $table->string('author_display', 120);
            $table->date('review_date');
            $table->smallInteger('rating');
            $table->string('title', 200)->nullable();
            $table->text('body');
            $table->char('lang', 2)->default('ES');
            $table->foreignUuid('product_id')->nullable()->constrained('catalog_products');
            $table->text('url')->nullable();
            $table->string('response_status', 20)->default('sin_responder');
            $table->text('reply')->nullable();
            $table->timestampTz('replied_at')->nullable();
            $table->uuid('replied_by')->nullable();
            $table->foreignUuid('conversation_id')->nullable()->constrained('collab_conversations');
            $table->boolean('is_example')->default(false);
            $table->jsonb('raw')->default('{}');
            $audit($table);
            $table->unique(['tenant_id', 'source_id', 'external_id']);
            $table->index(['tenant_id', 'review_date']);
        });
        $checks('channel_external_reviews', ['rating_check' => 'rating BETWEEN 1 AND 5',
            'response_check' => "response_status IN ('sin_responder','pendiente_publicar','publicada','no_admite')"]);

        // §3.8 widget: configuración (1 por tenant), dominios autorizados y traspasos al checkout.
        Schema::create('channel_widget_config', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('tenant_slug', 60);
            $table->string('public_key', 40)->unique();
            $table->string('public_key_prev', 40)->nullable();
            $table->timestampTz('prev_expires_at')->nullable();
            $table->jsonb('defaults')->default('{}');
            $table->jsonb('reviews_widget')->default('{}');
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique('tenant_id');
            $table->index('public_key_prev');
        });
        Schema::create('channel_widget_domains', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('domain', 253);
            $table->string('cms', 40)->nullable();
            $table->string('status', 15)->default('pendiente');
            $table->string('verify_token', 60);
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('last_check_at')->nullable();
            $audit($table);
            $table->unique(['tenant_id', 'domain']);
            $table->index('domain');
        });
        $checks('channel_widget_domains', [
            'domain_check' => "domain ~ '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$'",
            'status_check' => "status IN ('pendiente','verificado')",
        ]);
        Schema::create('channel_widget_handoffs', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->foreignUuid('product_id')->constrained('catalog_products');
            $table->date('service_date');
            $table->jsonb('pax');
            $table->jsonb('addons')->default('[]');
            $table->jsonb('lodging')->default('[]');
            $table->jsonb('removed')->default('[]');
            $table->char('currency_display', 3);
            $table->decimal('fx_rate', 12, 6)->default(1);
            $table->string('payment_mode', 15);
            $table->char('lang', 2)->default('es');
            $table->string('origin_domain', 253)->nullable();
            $table->jsonb('quote_snapshot');
            $table->jsonb('rules_applied')->default('[]');
            $table->string('status', 15)->default('pendiente');
            $table->uuid('reservation_id')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->timestampTz('expires_at');
            $audit($table);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status', 'expires_at']);
        });
        $checks('channel_widget_handoffs', ['status_check' => "status IN ('pendiente','en_carrito','convertido','expirado')"]);

        foreach (self::TABLES as $table) {
            Rls::tenantTable($table);
        }
        // CR-04: las reglas personalizadas se eliminan.
        foreach (['notif_pax_message_templates', 'channel_widget_domains', 'sys_booking_rules'] as $table) {
            DB::statement("GRANT DELETE ON {$table} TO ".config('turismo.db_roles.app'));
        }
        // WG-01/02: la clave pública y los orígenes verificados se resuelven antes de conocer el tenant (conexión de plataforma).
        $platform = config('turismo.db_roles.platform');
        foreach (['channel_widget_config', 'channel_widget_domains'] as $table) {
            DB::statement("CREATE POLICY platform_read ON {$table} FOR SELECT TO {$platform} USING (true)");
            Rls::grantPlatform($table, ['SELECT']);
        }

        foreach (DB::table('tenants')->get(['id']) as $tenant) {
            $order = (int) DB::table('sys_booking_rules')->where('tenant_id', $tenant->id)->max('sort_order');
            $t = DB::table('platform_booking_rule_templates')->where('rule_key', 'R-OWN-REVIEWS')->first();
            DB::table('sys_booking_rules')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'rule_key' => $t->rule_key, 'group_name' => $t->group_name,
                'name' => $t->name, 'description' => $t->description, 'enabled' => $t->enabled, 'params' => $t->params, 'scope' => $t->scope, 'product_override' => $t->product_override,
                'is_system' => true, 'params_schema' => $t->params_schema, 'sort_order' => max($order + 1, $t->sort_order), 'created_at' => $now, 'updated_at' => $now]);
            ExperienceProvisioner::seedTenant($tenant->id, DB::connection());
        }
    }

    public function down(): void
    {
        Schema::table('fin_fx_rates', function (Blueprint $table) {
            $table->dropForeign(['provider_id']);
            $table->dropColumn(['previous_rate', 'provider_id', 'daily_change_pct', 'alert_raised', 'is_demo', 'effective_at']);
        });
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
        Schema::dropIfExists('sys_rule_templates');
        Schema::table('booking_reservations', fn (Blueprint $table) => $table->dropColumn('currency_display'));
        Schema::table('sys_booking_rules', fn (Blueprint $table) => $table->dropColumn('depends_on'));
        DB::table('sys_booking_rules')->where('rule_key', 'R-OWN-REVIEWS')->delete();
        DB::table('platform_booking_rule_templates')->where('rule_key', 'R-OWN-REVIEWS')->delete();
        DB::table('sys_settings')->where('section', 'ecommerce')->delete();
        DB::table('sys_sequences')->whereIn('doc_type', ['pax_template', 'pax_message', 'widget_handoff'])->delete();
    }
};
