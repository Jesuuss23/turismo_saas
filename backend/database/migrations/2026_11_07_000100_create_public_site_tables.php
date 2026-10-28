<?php

use App\Services\Site\SiteProvisioner;
use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['channel_web_carts', 'channel_web_cart_items', 'channel_ecom_order_lines', 'catalog_product_translations', 'catalog_destination_contents'];

    // Spec 15 §3: sitio web público (carrito multiproducto con holds, pedido multilínea, lista de deseos, eventos del embudo y contenido del sitio).
    public function up(): void
    {
        $base = function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
        };
        $checks = function (string $table, array $checks) {
            foreach ($checks as $name => $check) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$name} CHECK ({$check})");
            }
        };

        // §3.1 carrito persistente (token opaco en cookie HttpOnly; solo se guarda su SHA-256).
        Schema::create('channel_web_carts', function (Blueprint $table) use ($base) {
            $base($table);
            $table->string('code', 20);
            $table->char('token_hash', 64);
            $table->foreignUuid('crm_client_id')->nullable()->constrained('crm_clients');
            $table->string('promo_code', 40)->nullable();
            $table->char('currency', 3)->default('PEN');
            $table->string('locale', 5)->default('es');
            $table->string('status', 20)->default('activo');
            $table->uuid('order_id')->nullable()->unique();
            $table->string('email', 190)->nullable();
            $table->jsonb('utm')->nullable();
            $table->timestampTz('last_activity_at');
            $table->timestampsTz();
            $table->integer('lock_version')->default(0);
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'token_hash']);
        });
        $checks('channel_web_carts', [
            'status_check' => "status IN ('activo','convertido','abandonado','expirado')",
            'currency_check' => "currency IN ('PEN','USD')",
            'locale_check' => "locale IN ('es','en')",
        ]);
        DB::statement("CREATE INDEX channel_web_carts_activity ON channel_web_carts (tenant_id, last_activity_at) WHERE status = 'activo'");

        // §3.2 líneas: el precio no se guarda (RT-5), se recalcula en cada lectura; un hold vivo por línea.
        Schema::create('channel_web_cart_items', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('cart_id')->constrained('channel_web_carts')->cascadeOnDelete();
            $table->smallInteger('line_no');
            $table->foreignUuid('product_id')->constrained('catalog_products');
            $table->date('service_date');
            $table->foreignUuid('departure_id')->nullable()->constrained('catalog_product_departures');
            $table->smallInteger('pax_adults');
            $table->smallInteger('pax_children')->default(0);
            $table->smallInteger('pax_infants')->default(0);
            $table->jsonb('options')->default('{}');
            $table->jsonb('extras')->default('{}');
            $table->foreignUuid('hold_id')->nullable()->unique()->constrained('catalog_availability_holds');
            $table->timestampTz('hold_expires_at')->nullable();
            $table->timestampTz('added_at');
            $table->timestampsTz();
            $table->index('cart_id');
            $table->unique(['cart_id', 'line_no']);
        });
        $checks('channel_web_cart_items', ['pax_check' => 'pax_adults >= 1 AND pax_children >= 0 AND pax_infants >= 0 AND pax_infants <= pax_adults']);

        // §3.3 pedido web multiproducto: product/fecha/pax quedan con la primera línea (compatibilidad con la tienda de 08).
        Schema::table('channel_ecom_orders', function (Blueprint $table) {
            $table->foreignUuid('cart_id')->nullable()->constrained('channel_web_carts');
            $table->string('sales_channel', 20)->default('sitio_web');
            $table->string('locale', 5)->default('es');
            $table->decimal('fx_rate', 12, 6)->default(1);
            $table->string('pay_mode', 10)->default('total');
            $table->decimal('deposit_pct', 5, 2)->default(100);
            $table->decimal('amount_due_now', 14, 2)->nullable();
            $table->date('balance_due_date')->nullable();
            $table->jsonb('billing')->nullable();
            $table->jsonb('consents')->nullable();
            $table->smallInteger('pax_infants')->default(0);
        });
        DB::statement('ALTER TABLE channel_ecom_orders DROP CONSTRAINT channel_ecom_orders_status_check');
        $checks('channel_ecom_orders', [
            'status_check' => "status IN ('pago_pendiente','pago_rechazado','pago_por_validar','confirmado','cancelado','expirado')",
            'pay_mode_check' => "pay_mode IN ('total','anticipo')",
            'channel_check' => "sales_channel IN ('sitio_web','tienda_backoffice')",
            'balance_check' => "pay_mode = 'total' OR balance_due_date IS NOT NULL",
        ]);

        // §3.4 líneas del pedido (una reserva por línea, v2.1 §3.14).
        Schema::create('channel_ecom_order_lines', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('order_id')->constrained('channel_ecom_orders');
            $table->smallInteger('line_no');
            $table->foreignUuid('product_id')->constrained('catalog_products');
            $table->string('product_version', 10);
            $table->date('service_date');
            $table->foreignUuid('departure_id')->nullable()->constrained('catalog_product_departures');
            $table->smallInteger('pax_adults');
            $table->smallInteger('pax_children')->default(0);
            $table->smallInteger('pax_infants')->default(0);
            $table->foreignUuid('rate_id')->constrained('catalog_rates');
            $table->uuid('season_id')->nullable();
            $table->decimal('season_factor', 6, 4)->default(1);
            $table->decimal('unit_adult', 14, 2);
            $table->decimal('unit_child', 14, 2);
            $table->smallInteger('units')->nullable();
            $table->jsonb('config_snapshot')->default('[]');
            $table->jsonb('extras_snapshot')->default('[]');
            $table->decimal('subtotal', 14, 2);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('extras_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->char('currency', 3);
            $table->decimal('total_order_currency', 14, 2);
            $table->foreignUuid('promotion_id')->nullable()->constrained('catalog_product_promotions');
            $table->decimal('cost_total', 14, 2)->default(0);
            $table->foreignUuid('hold_id')->nullable()->constrained('catalog_availability_holds');
            $table->foreignUuid('reservation_id')->nullable()->constrained('booking_reservations');
            $table->timestampsTz();
            $table->index('order_id');
            $table->unique(['order_id', 'line_no']);
        });

        // §3.5 pasajeros: nacimiento, nacionalidad, dieta e infantes; documento único por pedido.
        Schema::table('channel_ecom_passengers', function (Blueprint $table) {
            $table->string('first_name', 80)->nullable();
            $table->string('last_name', 80)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('nationality', 60)->nullable();
            $table->string('diet', 120)->nullable();
        });
        DB::statement('ALTER TABLE channel_ecom_passengers DROP CONSTRAINT channel_ecom_passengers_pax_type_check');
        $checks('channel_ecom_passengers', ['pax_type_check' => "pax_type IN ('ADT','CHD','INF')"]);
        DB::statement('CREATE UNIQUE INDEX channel_ecom_passengers_doc_unique ON channel_ecom_passengers (order_id, doc_type, doc_number) WHERE doc_type IS NOT NULL');

        // §3.6 intentos: medio, referencia de operación (transferencia/Yape), monto y clave de idempotencia.
        Schema::table('channel_ecom_payment_attempts', function (Blueprint $table) {
            $table->string('method', 20)->default('tarjeta');
            $table->string('operation_ref', 60)->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('idempotency_key', 80)->nullable();
            $table->unique(['tenant_id', 'idempotency_key']);
        });
        DB::statement('ALTER TABLE channel_ecom_payment_attempts ALTER COLUMN card_masked DROP NOT NULL');
        DB::statement('ALTER TABLE channel_ecom_payment_attempts DROP CONSTRAINT channel_ecom_payment_attempts_result_check');
        $checks('channel_ecom_payment_attempts', [
            'result_check' => "result IN ('aprobado','rechazado','por_validar')",
            'method_check' => "method IN ('tarjeta','transferencia','yape','plin')",
        ]);

        // §3.7 lista de deseos del cliente (anónimos: localStorage, se fusiona al ingresar).
        Schema::create('channel_wishlist_items', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('crm_client_id')->constrained('crm_clients');
            $table->foreignUuid('product_id')->constrained('catalog_products');
            $table->timestampTz('created_at');
            $table->primary(['tenant_id', 'crm_client_id', 'product_id']);
        });

        // §3.8 eventos del embudo (GA4). Tabla simple con índice por fecha; la partición mensual queda para producción.
        Schema::create('channel_web_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->timestampTz('occurred_at');
            $table->string('session_id', 40);
            $table->uuid('client_id')->nullable();
            $table->string('event', 40);
            $table->jsonb('params')->default('{}');
            $table->string('locale', 5)->default('es');
            $table->char('currency', 3)->default('PEN');
            $table->jsonb('utm')->nullable();
            $table->index(['tenant_id', 'occurred_at']);
        });

        // §3.9 leads web: solicitud, cliente coincidente, SLA y consentimiento.
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->jsonb('web_request')->nullable();
            $table->uuid('matched_client_id')->nullable();
            $table->timestampTz('sla_due_at')->nullable();
            $table->jsonb('consent')->nullable();
        });

        // §3.10 cuentas creadas desde el sitio y snapshot de consentimientos del cliente.
        Schema::table('iam_users', function (Blueprint $table) {
            $table->string('created_via', 20)->default('backoffice');
        });
        if (! Schema::hasColumn('iam_users', 'email_verified_at')) {
            Schema::table('iam_users', fn (Blueprint $table) => $table->timestampTz('email_verified_at')->nullable());
        }
        Schema::table('crm_clients', fn (Blueprint $table) => $table->jsonb('consents')->nullable());

        // §3.11 contenido del sitio por producto y por destino.
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->string('difficulty', 30)->nullable();
            $table->jsonb('guide_languages')->default('["ES"]');
            $table->string('pickup_text', 200)->nullable();
            $table->decimal('rating_avg', 3, 2)->nullable();
            $table->integer('rating_count')->default(0);
        });
        $checks('catalog_products', ['difficulty_check' => "difficulty IS NULL OR difficulty IN ('facil','moderada','moderada_exigente','exigente')"]);

        Schema::create('catalog_product_translations', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name', 160);
            $table->text('short')->nullable();
            $table->text('long')->nullable();
            $table->jsonb('inclusions')->nullable();
            $table->jsonb('exclusions')->nullable();
            $table->jsonb('faqs')->nullable();
            $table->string('seo_title', 120)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->string('slug', 160);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'locale', 'slug']);
            $table->unique(['product_id', 'locale']);
        });

        Schema::create('catalog_destination_contents', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('destination_id')->constrained('catalog_destinations')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('slug', 120);
            $table->string('tagline', 200)->nullable();
            $table->text('description')->nullable();
            $table->string('climate', 300)->nullable();
            $table->string('altitude_text', 60)->nullable();
            $table->jsonb('faqs')->nullable();
            $table->boolean('featured')->default(false);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'locale', 'slug']);
            $table->unique(['destination_id', 'locale']);
        });

        // Doble opt-in del newsletter (SIT-BR-25).
        Schema::table('mkt_contacts_subscribers', fn (Blueprint $table) => $table->char('opt_in_token_hash', 64)->nullable());

        foreach (self::TABLES as $table) {
            Rls::tenantTable($table);
        }
        DB::statement('GRANT DELETE ON channel_web_cart_items TO '.config('turismo.db_roles.app'));
        Rls::tenantTable('channel_wishlist_items', ['SELECT', 'INSERT', 'DELETE']);
        Rls::tenantTable('channel_web_events', ['SELECT', 'INSERT']);
        DB::statement('GRANT USAGE, SELECT ON SEQUENCE channel_web_events_id_seq TO '.config('turismo.db_roles.app'));

        foreach (DB::table('tenants')->get(['id']) as $tenant) {
            SiteProvisioner::seedTenant($tenant->id, DB::connection());
        }
    }

    public function down(): void
    {
        DB::table('sys_sequences')->whereIn('doc_type', array_keys(SiteProvisioner::SEQUENCES))->delete();
        Schema::table('mkt_contacts_subscribers', fn (Blueprint $table) => $table->dropColumn('opt_in_token_hash'));
        Schema::dropIfExists('catalog_destination_contents');
        Schema::dropIfExists('catalog_product_translations');
        DB::statement('ALTER TABLE catalog_products DROP CONSTRAINT catalog_products_difficulty_check');
        Schema::table('catalog_products', fn (Blueprint $table) => $table->dropColumn(['difficulty', 'guide_languages', 'pickup_text', 'rating_avg', 'rating_count']));
        Schema::table('crm_clients', fn (Blueprint $table) => $table->dropColumn('consents'));
        Schema::table('iam_users', fn (Blueprint $table) => $table->dropColumn('created_via'));
        Schema::table('crm_leads', fn (Blueprint $table) => $table->dropColumn(['web_request', 'matched_client_id', 'sla_due_at', 'consent']));
        Schema::dropIfExists('channel_web_events');
        Schema::dropIfExists('channel_wishlist_items');
        DB::statement('ALTER TABLE channel_ecom_payment_attempts DROP CONSTRAINT channel_ecom_payment_attempts_method_check');
        DB::statement('ALTER TABLE channel_ecom_payment_attempts DROP CONSTRAINT channel_ecom_payment_attempts_result_check');
        DB::statement("ALTER TABLE channel_ecom_payment_attempts ADD CONSTRAINT channel_ecom_payment_attempts_result_check CHECK (result IN ('aprobado','rechazado'))");
        Schema::table('channel_ecom_payment_attempts', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'idempotency_key']);
            $table->dropColumn(['method', 'operation_ref', 'amount', 'currency', 'idempotency_key']);
        });
        DB::statement('DROP INDEX IF EXISTS channel_ecom_passengers_doc_unique');
        DB::statement('ALTER TABLE channel_ecom_passengers DROP CONSTRAINT channel_ecom_passengers_pax_type_check');
        DB::statement("ALTER TABLE channel_ecom_passengers ADD CONSTRAINT channel_ecom_passengers_pax_type_check CHECK (pax_type IN ('ADT','CHD'))");
        Schema::table('channel_ecom_passengers', fn (Blueprint $table) => $table->dropColumn(['first_name', 'last_name', 'birth_date', 'nationality', 'diet']));
        Schema::dropIfExists('channel_ecom_order_lines');
        foreach (['status_check', 'pay_mode_check', 'channel_check', 'balance_check'] as $c) {
            DB::statement("ALTER TABLE channel_ecom_orders DROP CONSTRAINT channel_ecom_orders_{$c}");
        }
        DB::statement("ALTER TABLE channel_ecom_orders ADD CONSTRAINT channel_ecom_orders_status_check CHECK (status IN ('pago_pendiente','pago_rechazado','confirmado','cancelado','expirado'))");
        Schema::table('channel_ecom_orders', function (Blueprint $table) {
            $table->dropForeign(['cart_id']);
            $table->dropColumn(['cart_id', 'sales_channel', 'locale', 'fx_rate', 'pay_mode', 'deposit_pct', 'amount_due_now', 'balance_due_date', 'billing', 'consents', 'pax_infants']);
        });
        Schema::dropIfExists('channel_web_cart_items');
        Schema::dropIfExists('channel_web_carts');
    }
};
