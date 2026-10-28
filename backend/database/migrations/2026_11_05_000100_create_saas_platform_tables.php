<?php

use App\Services\Admin\AdminCatalog;
use App\Services\Platform\PlatformCatalog;
use App\Services\Platform\PlatformProvisioner;
use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    // Spec 13 §3: plataforma SaaS. Tablas de plataforma (sin RLS, rol de plataforma; la app solo lee los catálogos)
    // y tablas del tenant con RLS (API keys, webhooks, entregas y apps OAuth).
    public function up(): void
    {
        $platform = config('turismo.db_roles.platform');
        $checks = function (string $table, array $checks) {
            foreach ($checks as $name => $check) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$name} CHECK ({$check})");
            }
        };
        $metrics = "'".implode("','", array_keys(PlatformCatalog::METRICS))."'";
        $now = now();

        // §3.2 plans: precio anual, días de prueba e ids de la pasarela (PLN-BR-01: el anual no supera 12 mensualidades).
        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('price_year', 14, 2)->default(0);
            $table->smallInteger('trial_days')->default(14);
            $table->jsonb('gateway_price_ids')->nullable();
            $table->integer('lock_version')->default(0);
        });
        foreach (PlatformCatalog::PLAN_EXTRA as $id => $extra) {
            DB::table('plans')->where('id', $id)->update($extra);
        }
        $checks('plans', ['price_year_check' => 'price_year >= 0 AND price_year <= price_monthly * 12', 'trial_days_check' => 'trial_days BETWEEN 0 AND 60']);
        Rls::grantPlatform('plans', ['UPDATE']);
        Rls::grantPlatform('plan_features', ['INSERT', 'UPDATE']);

        // §3.3 límites por plan (NULL = ilimitado, 0 = no incluido; hard bloquea al 100 %).
        Schema::create('plan_limits', function (Blueprint $table) {
            $table->string('plan_id', 30);
            $table->foreign('plan_id')->references('id')->on('plans');
            $table->string('metric', 30);
            $table->bigInteger('value')->nullable();
            $table->boolean('hard')->default(true);
            $table->primary(['plan_id', 'metric']);
        });
        $checks('plan_limits', ['metric_check' => "metric IN ({$metrics})", 'value_check' => 'value IS NULL OR value >= 0']);
        foreach (PlatformCatalog::LIMITS as $plan => $limits) {
            foreach ($limits as $metric => $value) {
                DB::table('plan_limits')->insert(['plan_id' => $plan, 'metric' => $metric, 'value' => $value, 'hard' => PlatformCatalog::METRICS[$metric][3]]);
            }
        }

        // §3.5 add-ons y precio por plan.
        Schema::create('addons', function (Blueprint $table) {
            $table->string('code', 40)->primary();
            $table->string('label', 80);
            $table->string('unit', 30);
            $table->boolean('quantity_allowed');
            $table->decimal('price_month', 14, 2);
            $table->jsonb('available_plans');
            $table->jsonb('included_plans');
            $table->jsonb('grants');
            $table->smallInteger('sort')->default(0);
        });
        Schema::create('addon_prices', function (Blueprint $table) {
            $table->string('addon_code', 40);
            $table->foreign('addon_code')->references('code')->on('addons');
            $table->string('plan_id', 30);
            $table->foreign('plan_id')->references('id')->on('plans');
            $table->decimal('price', 14, 2);
            $table->primary(['addon_code', 'plan_id']);
        });
        $i = 0;
        foreach (PlatformCatalog::ADDONS as $code => [$label, $unit, $qty, $price, $available, $included, $grants]) {
            DB::table('addons')->insert(['code' => $code, 'label' => $label, 'unit' => $unit, 'quantity_allowed' => $qty, 'price_month' => $price,
                'available_plans' => json_encode($available), 'included_plans' => json_encode($included), 'grants' => json_encode($grants), 'sort' => ++$i]);
        }
        foreach (PlatformCatalog::ADDON_PRICES as $code => $prices) {
            foreach ($prices as $plan => $price) {
                DB::table('addon_prices')->insert(['addon_code' => $code, 'plan_id' => $plan, 'price' => $price]);
            }
        }

        // §3.4 catálogo de features (las filas por plan siguen en plan_features, spec 09).
        Schema::create('features', function (Blueprint $table) {
            $table->string('key', 50)->primary();
            $table->string('label', 120);
            $table->string('module', 40);
            $table->string('kind', 20);
            $table->string('addon_code', 40)->nullable();
            $table->foreign('addon_code')->references('code')->on('addons');
            $table->jsonb('routes')->default('[]');
            $table->smallInteger('sort')->default(0);
            $table->timestampTz('created_at')->useCurrent();
        });
        $checks('features', ['kind_check' => "kind IN ('module','limit','addon','beta')"]);
        $ranks = array_map(fn ($p) => $p[1], AdminCatalog::PLANS);
        $i = 0;
        foreach (AdminCatalog::FEATURES as $key => $f) {
            [$module, $kind, $addon, $routes] = PlatformCatalog::FEATURE_META[$key];
            DB::table('features')->insert(['key' => $key, 'label' => $f[0], 'module' => $module, 'kind' => $kind, 'addon_code' => $addon, 'routes' => json_encode($routes), 'sort' => ++$i]);
            foreach (AdminCatalog::PLANS as $planId => $plan) {
                $included = isset($ranks[$f[1]]) && $plan[1] >= $ranks[$f[1]];
                DB::table('plan_features')->insertOrIgnore(['id' => (string) Str::uuid7(), 'feature_key' => $key, 'label' => $f[0], 'plan_id' => $planId,
                    'value' => $included ? ($f[2][$planId] ?? 'included') : 'not_included']);
            }
        }

        // §3.4 excepciones por tenant (PLN-BR-03: máximo 180 días).
        Schema::create('feature_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->index()->constrained('tenants');
            $table->string('feature_key', 50);
            $table->foreign('feature_key')->references('key')->on('features');
            $table->boolean('value');
            $table->string('reason', 300);
            $table->date('valid_until')->nullable();
            $table->foreignUuid('granted_by')->constrained('platform_users');
            $table->timestampTz('created_at');
            $table->unique(['tenant_id', 'feature_key']);
        });
        $checks('feature_overrides', ['valid_until_check' => 'valid_until IS NULL OR valid_until <= (created_at::date + 180)']);

        // §3.6 suscripciones: una viva por tenant.
        Schema::create('platform_sequences', function (Blueprint $table) {
            $table->string('series', 10)->primary();
            $table->integer('next_number');
        });
        foreach (['FQ01', 'NQ01', 'SUB'] as $series) {
            DB::table('platform_sequences')->insert(['series' => $series, 'next_number' => 1]);
        }

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('code', 30)->unique();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('plan_id', 30);
            $table->foreign('plan_id')->references('id')->on('plans');
            $table->string('cycle', 10);
            $table->string('status', 30);
            $table->date('trial_starts_at')->nullable();
            $table->date('trial_ends_at')->nullable();
            $table->smallInteger('trial_extended_days')->default(0);
            $table->date('current_period_start');
            $table->date('current_period_end');
            $table->smallInteger('billing_anchor_day');
            $table->boolean('auto_renew')->default(true);
            $table->date('cancel_at')->nullable();
            $table->jsonb('cancel_survey')->nullable();
            $table->decimal('credit_balance', 14, 2)->default(0);
            $table->uuid('coupon_redemption_id')->nullable();
            // TNT-0004: precio pactado de la suscripción (§11), no del plan.
            $table->decimal('negotiated_price', 14, 2)->nullable();
            $table->date('price_locked_until')->nullable();
            $table->string('gateway', 20)->default('local_gateway');
            $table->string('gateway_customer_id', 100)->nullable();
            $table->string('gateway_subscription_id', 100)->nullable();
            $table->char('billing_ruc', 11);
            $table->string('billing_name', 200);
            $table->string('billing_address', 200);
            $table->char('billing_ubigeo', 6)->nullable();
            $table->string('billing_email', 150);
            $table->integer('lock_version')->default(0);
            $table->timestampsTz();
            $table->index('status');
            $table->index('current_period_end');
        });
        DB::statement("CREATE UNIQUE INDEX subscriptions_live_unique ON subscriptions (tenant_id) WHERE status <> 'canceled'");
        DB::statement("CREATE INDEX subscriptions_trial_index ON subscriptions (trial_ends_at) WHERE status = 'trialing'");
        $checks('subscriptions', [
            'cycle_check' => "cycle IN ('monthly','annual')",
            'status_check' => "status IN ('trialing','active','past_due','cancel_scheduled','suspended','canceled')",
            'trial_extended_check' => 'trial_extended_days BETWEEN 0 AND 14',
            'period_check' => 'current_period_end > current_period_start',
            'anchor_check' => 'billing_anchor_day BETWEEN 1 AND 28',
            'ruc_check' => "billing_ruc ~ '^(10|15|17|20)[0-9]{9}$'",
            'credit_check' => 'credit_balance >= 0',
        ]);

        Schema::create('subscription_items', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('subscription_id')->constrained('subscriptions');
            $table->string('addon_code', 40);
            $table->foreign('addon_code')->references('code')->on('addons');
            $table->smallInteger('quantity');
            $table->jsonb('meta')->default('{}');
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->decimal('unit_price', 14, 2);
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX subscription_items_live_unique ON subscription_items (subscription_id, addon_code) WHERE ends_at IS NULL');
        $checks('subscription_items', ['quantity_check' => 'quantity >= 1']);

        Schema::create('subscription_changes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('subscription_id')->constrained('subscriptions');
            $table->string('kind', 20);
            $table->string('from_plan_id', 30)->nullable();
            $table->string('to_plan_id', 30)->nullable();
            $table->string('from_cycle', 10)->nullable();
            $table->string('to_cycle', 10)->nullable();
            $table->date('effective_at');
            $table->string('status', 20);
            $table->jsonb('proration')->nullable();
            $table->uuid('invoice_id')->nullable();
            $table->uuid('requested_by')->nullable();
            $table->string('requested_by_name', 150)->nullable();
            $table->string('note', 300)->nullable();
            $table->timestampTz('created_at');
        });
        DB::statement("CREATE UNIQUE INDEX subscription_changes_scheduled_unique ON subscription_changes (subscription_id) WHERE status = 'scheduled'");
        $checks('subscription_changes', [
            'kind_check' => "kind IN ('upgrade','downgrade','cycle','addon_add','addon_update','addon_remove','cancel','resume')",
            'status_check' => "status IN ('scheduled','applied','voided')",
        ]);

        // §3.7 tarjeta tokenizada (PCI DSS SAQ-A: nunca PAN ni CVV).
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('gateway_token', 120);
            $table->string('brand', 20);
            $table->char('last4', 4);
            $table->smallInteger('exp_month');
            $table->smallInteger('exp_year');
            $table->string('holder', 120);
            $table->boolean('is_default')->default(false);
            $table->string('status', 20)->default('active');
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at');
        });
        DB::statement('CREATE UNIQUE INDEX payment_methods_default_unique ON payment_methods (tenant_id) WHERE is_default');
        $checks('payment_methods', ['status_check' => "status IN ('active','expired','removed')", 'last4_check' => "last4 ~ '^[0-9]{4}$'"]);

        // §3.8 comprobantes de la plataforma (emisor Qhapaq Cloud).
        Schema::create('platform_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->char('series', 4);
            $table->integer('number');
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('subscription_id')->constrained('subscriptions');
            $table->string('kind', 20);
            $table->date('period_from');
            $table->date('period_to');
            $table->date('issue_date');
            $table->date('due_date');
            $table->char('currency', 3)->default('PEN');
            $table->decimal('subtotal', 14, 2);
            $table->decimal('igv', 14, 2);
            $table->decimal('total', 14, 2);
            $table->decimal('igv_pct', 5, 2);
            $table->string('status', 20);
            $table->timestampTz('paid_at')->nullable();
            $table->string('payment_ref', 120)->nullable();
            $table->jsonb('bill_to');
            $table->string('sunat_status', 20)->default('pending');
            $table->string('sunat_hash', 80)->nullable();
            $table->smallInteger('sunat_attempts')->default(0);
            $table->string('sunat_error', 200)->nullable();
            $table->string('pdf_path', 255)->nullable();
            $table->string('xml_path', 255)->nullable();
            $table->date('dunning_failed_at')->nullable();
            $table->smallInteger('dunning_retries_done')->default(0);
            $table->timestampTz('dunning_next_retry_at')->nullable();
            $table->smallInteger('dunning_extended_days')->default(0);
            $table->string('void_reason', 300)->nullable();
            $table->timestampsTz();
            $table->unique(['series', 'number']);
            $table->index(['tenant_id', 'issue_date']);
        });
        DB::statement("CREATE INDEX platform_invoices_dunning_index ON platform_invoices (dunning_next_retry_at) WHERE status = 'failed'");
        $checks('platform_invoices', [
            'kind_check' => "kind IN ('subscription','proration','addon','manual')",
            'status_check' => "status IN ('draft','open','paid','failed','uncollectible','void')",
            'sunat_check' => "sunat_status IN ('pending','accepted','observed','rejected')",
            'total_check' => 'total = subtotal + igv',
        ]);

        Schema::create('platform_invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('invoice_id')->constrained('platform_invoices')->cascadeOnDelete();
            $table->string('description', 250);
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('amount', 14, 2);
            $table->string('line_type', 30);
        });
        $checks('platform_invoice_lines', ['type_check' => "line_type IN ('plan','addon','proration_charge','proration_credit','discount','usage_overage')"]);

        Schema::create('platform_credit_notes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->char('series', 4)->default('NQ01');
            $table->integer('number');
            $table->foreignUuid('invoice_id')->unique()->constrained('platform_invoices');
            $table->string('reason', 300);
            $table->decimal('amount', 14, 2);
            $table->string('sunat_status', 20)->default('pending');
            $table->string('refund_ref', 120)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at');
            $table->unique(['series', 'number']);
        });

        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('invoice_id')->constrained('platform_invoices');
            $table->smallInteger('attempt_no');
            $table->string('source', 20);
            $table->string('result', 20);
            $table->string('gateway_code', 40)->nullable();
            $table->string('message', 200)->nullable();
            $table->string('reference', 120)->nullable();
            $table->string('idempotency_key', 80)->unique();
            $table->timestampTz('created_at');
            $table->index(['invoice_id', 'created_at']);
        });
        $checks('payment_attempts', [
            'source_check' => "source IN ('initial','dunning','manual_retry','portal','manual_payment')",
            'result_check' => "result IN ('succeeded','declined','error')",
        ]);

        // §3.9 cupones (SUB-BR-07/08).
        Schema::create('coupons', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('code', 20)->unique();
            $table->string('type', 10);
            $table->decimal('value', 10, 2);
            $table->smallInteger('duration_cycles');
            $table->jsonb('plan_ids')->default('[]');
            $table->integer('max_redemptions');
            $table->integer('redeemed_count')->default(0);
            $table->date('valid_to');
            $table->string('status', 10)->default('active');
            $table->string('note', 200)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at');
        });
        $checks('coupons', [
            'code_check' => "code ~ '^[A-Z0-9_-]{4,20}$'",
            'type_check' => "type IN ('percent','fixed')",
            'value_check' => "value > 0 AND (type <> 'percent' OR value <= 50)",
            'duration_check' => 'duration_cycles BETWEEN 1 AND 12',
            'redeemed_check' => 'redeemed_count <= max_redemptions',
            'status_check' => "status IN ('active','inactive')",
        ]);
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('coupon_id')->constrained('coupons');
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('subscription_id')->constrained('subscriptions');
            $table->smallInteger('remaining_cycles');
            $table->timestampTz('created_at');
            $table->unique(['coupon_id', 'tenant_id']);
        });

        // §3.10 medición de uso (contador diario y consolidado mensual) y fallback si Redis falla.
        Schema::create('usage_counters', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('metric', 30);
            $table->date('day');
            $table->bigInteger('value');
            $table->primary(['tenant_id', 'metric', 'day']);
        });
        $checks('usage_counters', ['metric_check' => "metric IN ({$metrics})"]);
        Schema::create('usage_monthly', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('metric', 30);
            $table->date('month');
            $table->bigInteger('value');
            $table->bigInteger('limit_value')->nullable();
            $table->timestampTz('alert_80_sent_at')->nullable();
            $table->timestampTz('alert_100_sent_at')->nullable();
            $table->primary(['tenant_id', 'metric', 'month']);
        });
        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('metric', 30);
            $table->date('day');
            $table->integer('value');
            $table->timestampTz('created_at');
        });

        // §8.2 snapshot diario de MRR/ARR/churn y eventos del ciclo de vida.
        Schema::create('platform_metrics_daily', function (Blueprint $table) {
            $table->date('day')->primary();
            $table->decimal('mrr', 14, 2);
            $table->decimal('arr', 14, 2);
            $table->decimal('arpa', 14, 2);
            $table->integer('active_tenants');
            $table->integer('trialing_tenants');
            $table->integer('new_tenants');
            $table->integer('churned_tenants');
            $table->decimal('logo_churn_pct', 6, 2);
            $table->decimal('revenue_churn_pct', 6, 2);
            $table->integer('failed_payments');
            $table->jsonb('mrr_by_plan');
        });
        Schema::create('platform_events', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('kind', 30);
            $table->string('description', 300);
            $table->decimal('mrr_delta', 14, 2)->default(0);
            $table->timestampTz('created_at');
            $table->index('created_at');
        });
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 40)->primary();
            $table->jsonb('value');
            $table->uuid('updated_by')->nullable();
            $table->timestampTz('updated_at');
        });
        DB::table('platform_settings')->insert([
            ['key' => 'dunning_policy', 'value' => json_encode(PlatformCatalog::DUNNING), 'updated_by' => null, 'updated_at' => $now],
            ['key' => 'issuer', 'value' => json_encode(PlatformCatalog::ISSUER), 'updated_by' => null, 'updated_at' => $now],
        ]);

        // v2.2 Planes × reglas de reserva (DEMO editable): el plan mínimo; los planes de rank mayor heredan.
        Schema::create('booking_rule_catalog', function (Blueprint $table) {
            $table->string('code', 40)->primary();
            $table->string('group_key', 30);
            $table->string('label', 120);
            $table->smallInteger('sort');
        });
        Schema::create('plan_rule_entitlements', function (Blueprint $table) {
            $table->string('rule_code', 40)->primary();
            $table->foreign('rule_code')->references('code')->on('booking_rule_catalog');
            $table->string('min_plan', 30);
            $table->foreign('min_plan')->references('id')->on('plans');
            $table->boolean('is_demo')->default(true);
            $table->integer('lock_version')->default(0);
            $table->uuid('updated_by')->nullable();
            $table->timestampTz('updated_at');
        });
        $i = 0;
        foreach (PlatformCatalog::bookingRules() as $code => [$group, $label, $min]) {
            DB::table('booking_rule_catalog')->insert(['code' => $code, 'group_key' => $group, 'label' => $label, 'sort' => ++$i]);
            DB::table('plan_rule_entitlements')->insert(['rule_code' => $code, 'min_plan' => $min, 'is_demo' => true, 'updated_at' => $now]);
        }

        foreach (['plan_limits', 'addons', 'addon_prices', 'features', 'booking_rule_catalog', 'plan_rule_entitlements'] as $table) {
            Rls::grantApp($table, ['SELECT']);
        }
        foreach (['plan_limits', 'features', 'plan_rule_entitlements'] as $table) {
            Rls::grantPlatform($table, ['SELECT', 'INSERT', 'UPDATE']);
        }
        foreach (['addons', 'addon_prices', 'booking_rule_catalog'] as $table) {
            Rls::grantPlatform($table, ['SELECT']);
        }
        foreach (['feature_overrides', 'subscriptions', 'subscription_items', 'subscription_changes', 'payment_methods', 'platform_invoices', 'platform_invoice_lines',
            'platform_credit_notes', 'payment_attempts', 'coupons', 'coupon_redemptions', 'usage_counters', 'usage_monthly', 'platform_metrics_daily',
            'platform_events', 'platform_settings', 'platform_sequences'] as $table) {
            Rls::grantPlatform($table, ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
        }
        // Fallback de medición: la app escribe; la consolidación (plataforma) lee y borra.
        Rls::grantApp('usage_events', ['INSERT']);
        Rls::grantSequence('usage_events_id_seq');
        Rls::grantPlatform('usage_events', ['SELECT', 'DELETE']);

        // 402 PAYMENT_REQUIRED (§6): la suspensión por cobranza permite /billing/* y el login; la manual bloquea todo.
        Schema::table('tenants', fn (Blueprint $table) => $table->string('suspension_source', 20)->nullable());
        $checks('tenants', ['suspension_source_check' => "suspension_source IS NULL OR suspension_source IN ('manual','billing')"]);

        // §3.11–3.14 tablas del tenant (RLS). La plataforma solo lee API keys y webhooks (lookup por prefijo y contadores de uso).
        Schema::create('integration_api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('code', 30);
            $table->string('name', 120);
            $table->string('environment', 10);
            $table->string('prefix', 16)->unique();
            $table->char('last4', 4);
            $table->char('token_hash', 64);
            $table->jsonb('scopes');
            $table->jsonb('ip_allowlist')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestampTz('revoked_at')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->string('revoke_reason', 300)->nullable();
            $table->uuid('created_by');
            $table->timestampTz('created_at');
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });
        $checks('integration_api_keys', ['env_check' => "environment IN ('live','test')", 'status_check' => "status IN ('active','revoked','expired')"]);

        Schema::create('integration_webhooks', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('code', 30);
            $table->string('url', 500);
            $table->string('description', 200)->nullable();
            $table->jsonb('events');
            $table->text('secret_encrypted');
            $table->text('previous_secret_encrypted')->nullable();
            $table->timestampTz('previous_secret_expires_at')->nullable();
            $table->string('secret_prefix', 12);
            $table->string('api_version', 10);
            $table->string('status', 20)->default('active');
            $table->smallInteger('consecutive_failures')->default(0);
            $table->string('disabled_reason', 200)->nullable();
            $table->uuid('created_by');
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'code']);
        });
        DB::statement('CREATE INDEX integration_webhooks_events_gin ON integration_webhooks USING GIN (events)');
        $checks('integration_webhooks', ['url_check' => "url ~ '^https://'", 'status_check' => "status IN ('active','paused','disabled','deleted')"]);

        Schema::create('integration_webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('code', 30);
            $table->foreignUuid('webhook_id')->constrained('integration_webhooks');
            $table->string('event_type', 50);
            $table->string('event_id', 40);
            $table->string('entity_code', 40)->nullable();
            $table->jsonb('payload');
            $table->smallInteger('attempt')->default(0);
            $table->string('status', 20)->default('pending');
            $table->smallInteger('http_status')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->string('response_excerpt', 2000)->nullable();
            $table->string('error', 300)->nullable();
            $table->jsonb('request_headers')->nullable();
            $table->timestampTz('next_retry_at')->nullable();
            $table->boolean('manual')->default(false);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at')->nullable();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'webhook_id', 'created_at']);
        });
        DB::statement("CREATE INDEX integration_webhook_deliveries_retry_index ON integration_webhook_deliveries (status, next_retry_at) WHERE status IN ('pending','retrying')");
        $checks('integration_webhook_deliveries', ['status_check' => "status IN ('pending','delivered','retrying','failed')"]);

        Schema::create('integration_oauth_apps', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('code', 30);
            $table->string('name', 120);
            $table->string('vendor', 120);
            $table->string('client_id', 40)->unique();
            $table->char('secret_hash', 64);
            $table->string('secret_prefix', 12);
            $table->jsonb('redirect_uris');
            $table->jsonb('allowed_scopes');
            $table->string('status', 20)->default('active');
            $table->uuid('created_by');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'code']);
        });
        $checks('integration_oauth_apps', ['status_check' => "status IN ('active','revoked')"]);

        Rls::tenantTable('integration_api_keys', ['SELECT', 'INSERT', 'UPDATE']);
        Rls::tenantTable('integration_webhooks', ['SELECT', 'INSERT', 'UPDATE']);
        Rls::tenantTable('integration_webhook_deliveries', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
        Rls::tenantTable('integration_oauth_apps', ['SELECT', 'INSERT', 'UPDATE']);
        foreach (['integration_api_keys', 'integration_webhooks'] as $table) {
            DB::statement("CREATE POLICY platform_read ON {$table} FOR SELECT TO {$platform} USING (true)");
            Rls::grantPlatform($table, ['SELECT']);
        }

        // §3.10 gauges de consumo por tenant sin leer datos de negocio: usuarios facturables (roles internos y de app; los portales no
        // consumen licencia), sedes activas, almacenamiento, API keys activas y webhooks vigentes.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION platform_usage_gauges(p_tenant uuid DEFAULT NULL) RETURNS TABLE (
                tenant_id uuid, users integer, sedes integer, storage_bytes bigint, api_keys integer, webhooks integer
            ) LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
                SELECT t.id,
                    (SELECT count(DISTINCT u.id)::int FROM iam_users u JOIN model_has_roles m ON m.model_id = u.id JOIN roles r ON r.id = m.role_id
                        WHERE u.tenant_id = t.id AND u.status <> 'inactivo' AND u.deleted_at IS NULL AND r.scope IN ('tenant','mobile')),
                    (SELECT count(*)::int FROM iam_sedes s WHERE s.tenant_id = t.id AND s.status = 'activa' AND s.deleted_at IS NULL),
                    t.storage_used_bytes,
                    (SELECT count(*)::int FROM integration_api_keys k WHERE k.tenant_id = t.id AND k.status = 'active'),
                    (SELECT count(*)::int FROM integration_webhooks w WHERE w.tenant_id = t.id AND w.status IN ('active','paused','disabled') AND w.deleted_at IS NULL)
                FROM tenants t WHERE p_tenant IS NULL OR t.id = p_tenant
            $$
        SQL);
        DB::statement('REVOKE ALL ON FUNCTION platform_usage_gauges(uuid) FROM PUBLIC');
        DB::statement("GRANT EXECUTE ON FUNCTION platform_usage_gauges(uuid) TO {$platform}");

        // Correlativos por tenant y suscripción de los tenants existentes.
        foreach (DB::table('tenants')->get() as $tenant) {
            PlatformProvisioner::seedTenant($tenant->id, DB::connection());
            PlatformProvisioner::subscribe($tenant, DB::connection());
        }
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS platform_usage_gauges(uuid)');
        foreach (['integration_oauth_apps', 'integration_webhook_deliveries', 'integration_webhooks', 'integration_api_keys', 'plan_rule_entitlements', 'booking_rule_catalog',
            'platform_settings', 'platform_events', 'platform_metrics_daily', 'usage_events', 'usage_monthly', 'usage_counters', 'coupon_redemptions', 'coupons',
            'payment_attempts', 'platform_credit_notes', 'platform_invoice_lines', 'platform_invoices', 'payment_methods', 'subscription_changes', 'subscription_items',
            'subscriptions', 'platform_sequences', 'feature_overrides', 'features', 'addon_prices', 'addons', 'plan_limits'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::table('plan_features')->whereIn('feature_key', ['travel_docs', 'treasury', 'inbox_omnichannel', 'marketing_campaigns', 'api_access', 'webhooks', 'oauth_apps',
            'custom_domain', 'ai_quote_assistant'])->delete();
        DB::table('sys_sequences')->whereIn('doc_type', array_keys(PlatformProvisioner::SEQUENCES))->delete();
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_suspension_source_check');
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('suspension_source'));
        DB::statement('ALTER TABLE plans DROP CONSTRAINT IF EXISTS plans_price_year_check');
        DB::statement('ALTER TABLE plans DROP CONSTRAINT IF EXISTS plans_trial_days_check');
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn(['price_year', 'trial_days', 'gateway_price_ids', 'lock_version']));
    }
};
