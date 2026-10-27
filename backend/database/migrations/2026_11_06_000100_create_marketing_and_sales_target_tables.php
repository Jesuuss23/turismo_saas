<?php

use App\Services\Marketing\MarketingProvisioner;
use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'mkt_contacts_subscribers', 'mkt_consents', 'mkt_segments', 'mkt_templates', 'mkt_campaigns', 'mkt_campaign_tests', 'mkt_campaign_recipients',
        'mkt_campaign_suppressions', 'mkt_attributions', 'mkt_automations', 'mkt_automation_runs', 'mkt_automation_enrollments',
        'crm_sales_teams', 'crm_sales_team_members', 'crm_sales_targets', 'crm_sales_target_policies',
    ];

    // Spec 14 §3: marketing (contactos, consentimientos, segmentos, plantillas, campañas, atribución, automatizaciones) y metas comerciales.
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

        // §3.1 suscriptores del newsletter (contactos que aún no son leads ni clientes).
        Schema::create('mkt_contacts_subscribers', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->string('full_name', 160);
            $table->string('email', 160);
            $table->string('phone', 30)->nullable();
            $table->string('phone_e164', 20)->nullable()->index();
            $table->string('country', 60)->nullable();
            $table->string('interest_destination', 120)->nullable();
            $table->string('source', 60);
            $table->foreignUuid('client_id')->nullable()->constrained('crm_clients');
            $table->foreignUuid('lead_id')->nullable()->constrained('crm_leads');
            $table->date('birth_date')->nullable();
            $table->timestampTz('double_opt_in_at')->nullable();
            $audit($table);
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'code']);
        });
        DB::statement('CREATE UNIQUE INDEX mkt_contacts_subscribers_email_unique ON mkt_contacts_subscribers (tenant_id, lower(email))');

        // §3.4 segmentos dinámicos (reglas evaluadas al envío).
        Schema::create('mkt_segments', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('match', 5)->default('all');
            $table->jsonb('rules')->default('[]');
            $table->foreignUuid('owner_id')->constrained('iam_users');
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'code']);
        });
        DB::statement('CREATE UNIQUE INDEX mkt_segments_name_unique ON mkt_segments (tenant_id, lower(name)) WHERE deleted_at IS NULL');
        $checks('mkt_segments', ['match_check' => "match IN ('all','any')", 'rules_check' => "jsonb_typeof(rules) = 'array'"]);

        // §3.5 plantillas (email: activa; WhatsApp: en_revision → aprobada | rechazada).
        Schema::create('mkt_templates', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('channel', 20);
            $table->string('category', 20);
            $table->string('status', 20);
            $table->string('provider_template_id', 60)->nullable();
            $table->string('language', 10)->default('es');
            $table->string('subject', 200)->nullable();
            $table->text('body');
            $table->timestampTz('reviewed_at')->nullable();
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique(['tenant_id', 'code']);
        });
        $checks('mkt_templates', [
            'channel_check' => "channel IN ('email','whatsapp')", 'category_check' => "category IN ('MARKETING','UTILITY','SERVICIO')",
            'status_check' => "status IN ('activa','en_revision','aprobada','rechazada')",
            'subject_check' => "channel <> 'email' OR (subject IS NOT NULL AND subject <> '')",
            'name_check' => "channel <> 'whatsapp' OR name ~ '^[a-z0-9_]+$'",
            'status_channel_check' => "(channel = 'email') = (status = 'activa')",
        ]);

        // §3.6 campañas.
        Schema::create('mkt_campaigns', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->string('name', 160);
            $table->string('channel', 20);
            $table->foreignUuid('segment_id')->constrained('mkt_segments')->restrictOnDelete();
            $table->foreignUuid('template_id')->constrained('mkt_templates');
            $table->string('subject', 200)->nullable();
            $table->text('body');
            $table->uuid('promotion_id')->nullable();
            $table->string('promo_code', 30)->nullable();
            $table->string('utm', 200);
            $table->string('status', 20)->default('borrador');
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->jsonb('audience_snapshot')->nullable();
            $table->foreignUuid('owner_id')->constrained('iam_users');
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });
        $checks('mkt_campaigns', [
            'channel_check' => "channel IN ('email','whatsapp')", 'status_check' => "status IN ('borrador','programada','enviando','enviada','cancelada')",
            'scheduled_check' => "status <> 'programada' OR scheduled_at IS NOT NULL", 'sent_check' => "status NOT IN ('enviando','enviada') OR (sent_at IS NOT NULL AND audience_snapshot IS NOT NULL)",
        ]);
        DB::statement("CREATE INDEX mkt_campaigns_scheduled_idx ON mkt_campaigns (tenant_id, scheduled_at) WHERE status = 'programada'");
        DB::statement('CREATE INDEX mkt_campaigns_sent_idx ON mkt_campaigns (tenant_id, sent_at DESC)');

        // §3.2 consentimiento vigente por titular y canal + §3.3 historial inmutable.
        Schema::create('mkt_consents', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->string('subject_type', 20);
            $table->uuid('subject_id');
            $table->string('channel', 20);
            $table->string('status', 20);
            $table->timestampTz('granted_at');
            $table->string('source', 120);
            $table->text('evidence');
            $table->string('policy_version', 20);
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoke_source', 120)->nullable();
            $table->foreignUuid('revoke_campaign_id')->nullable()->constrained('mkt_campaigns');
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'subject_type', 'subject_id', 'channel']);
            $table->index(['tenant_id', 'subject_type', 'subject_id']);
        });
        $checks('mkt_consents', [
            'subject_check' => "subject_type IN ('client','lead','subscriber')", 'channel_check' => "channel IN ('email','whatsapp')",
            'status_check' => "status IN ('otorgado','revocado')", 'revoked_check' => "(status = 'revocado') = (revoked_at IS NOT NULL)",
        ]);
        DB::statement("CREATE INDEX mkt_consents_granted_idx ON mkt_consents (tenant_id, channel) WHERE status = 'otorgado'");
        Schema::create('mkt_consent_events', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('consent_id')->constrained('mkt_consents')->restrictOnDelete();
            $table->string('event', 20);
            $table->string('source', 120);
            $table->text('evidence')->nullable();
            $table->string('policy_version', 20)->nullable();
            $table->foreignUuid('campaign_id')->nullable()->constrained('mkt_campaigns');
            $table->foreignUuid('actor_id')->nullable()->constrained('iam_users');
            $table->timestampTz('occurred_at')->index();
            $table->ipAddress('ip')->nullable();
            $table->timestampTz('created_at')->nullable();
        });
        $checks('mkt_consent_events', ['event_check' => "event IN ('otorgado','revocado')"]);
        // MKT-BR-11: evidencia ante la ANPD, solo INSERT.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION mkt_consent_events_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'mkt_consent_events es de solo inserción (MKT-BR-11)';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER mkt_consent_events_immutable BEFORE UPDATE OR DELETE ON mkt_consent_events
                FOR EACH ROW EXECUTE FUNCTION mkt_consent_events_append_only();
        SQL);

        // §3.7 pruebas, §3.8 destinatarios, §3.9 supresiones, §3.10 atribución.
        Schema::create('mkt_campaign_tests', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('campaign_id')->index()->constrained('mkt_campaigns');
            $table->foreignUuid('to_user_id')->constrained('iam_users');
            $table->string('to_address', 160);
            $table->string('sample_subject_type', 20)->nullable();
            $table->uuid('sample_subject_id')->nullable();
            $table->timestampTz('sent_at');
            $table->timestampTz('invalidated_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();
        });
        Schema::create('mkt_campaign_recipients', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('campaign_id')->index()->constrained('mkt_campaigns');
            $table->string('subject_type', 20);
            $table->uuid('subject_id');
            $table->string('name', 160);
            $table->string('address', 160);
            $table->string('provider_message_id', 120)->nullable();
            $table->string('status', 20)->default('pendiente');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('clicked_at')->nullable();
            $table->timestampTz('replied_at')->nullable();
            $table->timestampTz('unsubscribed_at')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestampsTz();
            $table->unique(['campaign_id', 'subject_type', 'subject_id']);
            $table->index(['tenant_id', 'subject_type', 'subject_id', 'campaign_id']);
            $table->index('provider_message_id');
        });
        $checks('mkt_campaign_recipients', ['status_check' => "status IN ('pendiente','enviado','entregado','rebotado','fallido','suprimido')"]);
        Schema::create('mkt_campaign_suppressions', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('campaign_id')->index()->constrained('mkt_campaigns');
            $table->string('subject_type', 20);
            $table->uuid('subject_id');
            $table->string('name', 160);
            $table->string('reason', 20);
            $table->timestampTz('created_at')->nullable();
            $table->unique(['campaign_id', 'subject_type', 'subject_id']);
        });
        $checks('mkt_campaign_suppressions', ['reason_check' => "reason IN ('optout','nodata','consent','fatigue')"]);
        Schema::create('mkt_attributions', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('campaign_id')->index()->constrained('mkt_campaigns');
            $table->string('kind', 20);
            $table->foreignUuid('reservation_id')->nullable()->constrained('booking_reservations');
            $table->foreignUuid('lead_id')->nullable()->constrained('crm_leads');
            $table->foreignUuid('ecom_order_id')->nullable()->constrained('channel_ecom_orders');
            $table->string('subject_type', 20)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->decimal('revenue_pen', 14, 2)->nullable();
            $table->timestampTz('attributed_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'kind', 'reservation_id']);
            $table->unique(['tenant_id', 'kind', 'lead_id']);
            $table->unique(['tenant_id', 'kind', 'ecom_order_id']);
        });
        $checks('mkt_attributions', [
            'kind_check' => "kind IN ('reservation','lead','promo_use')",
            'ref_check' => "(kind = 'reservation' AND reservation_id IS NOT NULL) OR (kind = 'lead' AND lead_id IS NOT NULL) OR (kind = 'promo_use' AND ecom_order_id IS NOT NULL)",
        ]);

        // §3.11–3.12 automatizaciones, ejecuciones e inscripciones (MKT-BR-17).
        Schema::create('mkt_automations', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->string('key', 30);
            $table->string('name', 120);
            $table->string('trigger_desc', 200);
            $table->string('delay_desc', 80);
            $table->string('channel', 20);
            $table->foreignUuid('template_id')->constrained('mkt_templates');
            $table->string('purpose', 20);
            $table->string('status', 20)->default('pausada');
            $table->integer('sent_total')->default(0);
            $table->integer('suppressed_total')->default(0);
            $table->integer('conversions_total')->default(0);
            $table->timestampTz('last_run_at')->nullable();
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'key']);
        });
        $checks('mkt_automations', [
            'key_check' => "key IN ('welcome','cart','pretrip','survey','birthday','reactivation')", 'channel_check' => "channel IN ('email','whatsapp')",
            'purpose_check' => "purpose IN ('comercial','servicio')", 'status_check' => "status IN ('activa','pausada')",
        ]);
        Schema::create('mkt_automation_runs', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('automation_id')->index()->constrained('mkt_automations');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->integer('queued')->default(0);
            $table->integer('sent')->default(0);
            $table->integer('suppressed')->default(0);
            $table->foreignUuid('triggered_by')->nullable()->constrained('iam_users');
            $table->timestampTz('created_at')->nullable();
        });
        Schema::create('mkt_automation_enrollments', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('automation_id')->constrained('mkt_automations');
            $table->foreignUuid('run_id')->nullable()->constrained('mkt_automation_runs');
            $table->string('subject_type', 20);
            $table->uuid('subject_id');
            $table->string('name', 160);
            $table->string('ref_type', 20);
            $table->uuid('ref_id');
            $table->string('ref_code', 40)->nullable();
            $table->string('status', 20);
            $table->string('reason', 20)->nullable();
            $table->timestampTz('processed_at');
            $table->unique(['automation_id', 'subject_type', 'subject_id', 'ref_type', 'ref_id'], 'mkt_automation_enrollments_unique');
        });
        $checks('mkt_automation_enrollments', [
            'status_check' => "status IN ('enviado','suprimido')", 'ref_check' => "ref_type IN ('subscriber','ecom_order','reservation','client')",
        ]);

        // §3.13 equipos de venta.
        Schema::create('crm_sales_teams', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->string('name', 120);
            $table->foreignUuid('leader_id')->constrained('iam_users');
            $audit($table);
            $table->unique(['tenant_id', 'code']);
        });
        Schema::create('crm_sales_team_members', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('team_id')->constrained('crm_sales_teams');
            $table->foreignUuid('user_id')->constrained('iam_users');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->timestampsTz();
            $table->unique(['team_id', 'user_id', 'valid_from']);
        });

        // §3.14 metas comerciales, §3.15 ajustes, §3.16 política de bonos.
        Schema::create('crm_sales_targets', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->string('scope_type', 10);
            $table->uuid('scope_id');
            $table->string('period_type', 10);
            $table->string('period_key', 7);
            $table->date('period_from');
            $table->date('period_to');
            $table->decimal('goal_sales_pen', 14, 2);
            $table->integer('goal_bookings')->nullable();
            $table->decimal('goal_margin_pen', 14, 2)->nullable();
            $table->decimal('goal_conversion_pct', 5, 2)->nullable();
            $table->decimal('bonus_amount', 14, 2)->nullable();
            $table->char('bonus_currency', 3)->nullable();
            $table->string('status', 20)->default('borrador');
            $table->jsonb('result')->nullable();
            $table->uuid('bonus_commission_id')->nullable();
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'period_key']);
            $table->index(['tenant_id', 'scope_type', 'scope_id']);
        });
        $checks('crm_sales_targets', [
            'scope_check' => "scope_type IN ('asesor','sede','equipo')", 'period_type_check' => "period_type IN ('mes','trimestre')",
            'period_key_check' => "(period_type = 'mes' AND period_key ~ '^[0-9]{4}-(0[1-9]|1[0-2])$') OR (period_type = 'trimestre' AND period_key ~ '^[0-9]{4}-Q[1-4]$')",
            'period_check' => 'period_to >= period_from', 'goal_check' => 'goal_sales_pen > 0', 'bookings_check' => 'goal_bookings IS NULL OR goal_bookings >= 0',
            'conversion_check' => 'goal_conversion_pct IS NULL OR goal_conversion_pct BETWEEN 0 AND 100',
            'bonus_check' => "bonus_amount IS NULL OR (scope_type = 'asesor' AND bonus_amount >= 0)",
            'status_check' => "status IN ('borrador','vigente','cerrada','anulada')", 'result_check' => "status <> 'cerrada' OR result IS NOT NULL",
        ]);
        // MET-BR-01: una meta por alcance y período, salvo anuladas.
        DB::statement("CREATE UNIQUE INDEX crm_sales_targets_scope_unique ON crm_sales_targets (tenant_id, scope_type, scope_id, period_key) WHERE status <> 'anulada'");
        Schema::create('crm_sales_target_adjustments', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('target_id')->index()->constrained('crm_sales_targets');
            $table->string('action', 20);
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->text('reason')->nullable();
            $table->foreignUuid('actor_id')->nullable()->constrained('iam_users');
            $table->timestampTz('occurred_at');
        });
        $checks('crm_sales_target_adjustments', [
            'action_check' => "action IN ('crear','activar','ajustar','anular','cerrar','bono')",
            'reason_check' => "action NOT IN ('ajustar','anular') OR (reason IS NOT NULL AND btrim(reason) <> '')",
        ]);
        Schema::create('crm_sales_target_policies', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->jsonb('tiers')->default('[{"min":90,"pay":50},{"min":100,"pay":100},{"min":120,"pay":130}]');
            $table->string('primary_metric', 20)->default('sales');
            $table->integer('lock_decrease_after_pct')->default(50);
            $table->integer('attribution_window_days')->default(30);
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique('tenant_id');
        });

        // §3.17 cambios en otros módulos.
        Schema::table('catalog_product_promotions', function (Blueprint $table) {
            $table->foreignUuid('campaign_id')->nullable()->constrained('mkt_campaigns');
        });
        Schema::table('mkt_campaigns', function (Blueprint $table) {
            $table->foreign('promotion_id')->references('id')->on('catalog_product_promotions');
        });
        Schema::table('channel_ecom_orders', function (Blueprint $table) {
            $table->string('utm_campaign', 40)->nullable();
        });
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->string('utm_campaign', 40)->nullable();
        });
        DB::statement('ALTER TABLE fin_commissions ALTER COLUMN reservation_id DROP NOT NULL');
        Schema::table('fin_commissions', function (Blueprint $table) {
            $table->foreignUuid('sales_target_id')->nullable()->constrained('crm_sales_targets');
            $table->unique(['tenant_id', 'sales_target_id']);
        });
        DB::statement('ALTER TABLE fin_commissions DROP CONSTRAINT fin_commissions_kind_check');
        DB::statement("ALTER TABLE fin_commissions ADD CONSTRAINT fin_commissions_kind_check CHECK (kind IN ('vendedor','agencia_b2b','bono_meta'))");
        DB::statement("ALTER TABLE fin_commissions ADD CONSTRAINT fin_commissions_target_check CHECK ((kind = 'bono_meta') = (sales_target_id IS NOT NULL))");
        DB::statement("ALTER TABLE fin_commissions ADD CONSTRAINT fin_commissions_reservation_check CHECK (kind = 'bono_meta' OR reservation_id IS NOT NULL)");
        Schema::table('crm_sales_targets', function (Blueprint $table) {
            $table->foreign('bonus_commission_id')->references('id')->on('fin_commissions');
        });

        foreach (self::TABLES as $table) {
            Rls::tenantTable($table);
        }
        Rls::tenantTable('mkt_consent_events', ['SELECT', 'INSERT']);
        Rls::tenantTable('crm_sales_target_adjustments', ['SELECT', 'INSERT']);
        DB::statement('GRANT DELETE ON mkt_campaign_tests, crm_sales_team_members, mkt_attributions TO '.config('turismo.db_roles.app'));

        foreach (DB::table('tenants')->get(['id']) as $tenant) {
            MarketingProvisioner::seedTenant($tenant->id, DB::connection());
        }
    }

    public function down(): void
    {
        DB::table('sys_scheduled_jobs')->whereIn('code', MarketingProvisioner::JOBS)->whereNull('report_key')->delete();
        DB::table('sys_sequences')->whereIn('doc_type', array_keys(MarketingProvisioner::SEQUENCES))->delete();
        Schema::table('crm_sales_targets', fn (Blueprint $table) => $table->dropForeign(['bonus_commission_id']));
        DB::table('fin_commissions')->where('kind', 'bono_meta')->delete();
        DB::statement('ALTER TABLE fin_commissions DROP CONSTRAINT fin_commissions_target_check');
        DB::statement('ALTER TABLE fin_commissions DROP CONSTRAINT fin_commissions_reservation_check');
        DB::statement('ALTER TABLE fin_commissions DROP CONSTRAINT fin_commissions_kind_check');
        DB::statement("ALTER TABLE fin_commissions ADD CONSTRAINT fin_commissions_kind_check CHECK (kind IN ('vendedor','agencia_b2b'))");
        Schema::table('fin_commissions', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'sales_target_id']);
            $table->dropConstrainedForeignId('sales_target_id');
        });
        DB::statement('ALTER TABLE fin_commissions ALTER COLUMN reservation_id SET NOT NULL');
        Schema::table('crm_leads', fn (Blueprint $table) => $table->dropColumn('utm_campaign'));
        Schema::table('channel_ecom_orders', fn (Blueprint $table) => $table->dropColumn('utm_campaign'));
        Schema::table('mkt_campaigns', fn (Blueprint $table) => $table->dropForeign(['promotion_id']));
        Schema::table('catalog_product_promotions', fn (Blueprint $table) => $table->dropConstrainedForeignId('campaign_id'));
        foreach (['crm_sales_target_policies', 'crm_sales_target_adjustments', 'crm_sales_targets', 'crm_sales_team_members', 'crm_sales_teams',
            'mkt_automation_enrollments', 'mkt_automation_runs', 'mkt_automations', 'mkt_attributions', 'mkt_campaign_suppressions', 'mkt_campaign_recipients',
            'mkt_campaign_tests', 'mkt_consent_events', 'mkt_consents', 'mkt_campaigns', 'mkt_templates', 'mkt_segments', 'mkt_contacts_subscribers'] as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table} CASCADE");
        }
        DB::statement('DROP FUNCTION IF EXISTS mkt_consent_events_append_only()');
    }
};
