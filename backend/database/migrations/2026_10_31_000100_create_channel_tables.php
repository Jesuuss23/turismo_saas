<?php

use App\Services\Channels\ChannelProvisioner;
use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'channel_b2b_price_lists', 'channel_b2b_credit_movements', 'channel_b2b_settlement_requests', 'channel_ecom_orders', 'channel_ecom_passengers',
        'channel_ecom_payment_attempts', 'cms_contents', 'cms_content_events', 'channel_support_tickets', 'channel_support_ticket_events',
        'channel_portal_access_links', 'ops_incident_pax_acks', 'channel_pax_feedback',
    ];

    // Spec 08 §3: Canales y portales. Completa la agencia B2B mínima de Finanzas, agrega listas de precios, libro de crédito,
    // liquidaciones, pedidos web, CMS, tickets de portal, acceso del pasajero y confirmaciones de alertas.
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
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_{$name}");
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$name} CHECK ({$check})");
            }
        };

        // §3.2
        Schema::create('channel_b2b_price_lists', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('name', 80);
            $table->smallInteger('year')->nullable();
            $table->string('status', 10)->default('activa');
            $audit($table);
            $table->unique(['tenant_id', 'name']);
        });
        $checks('channel_b2b_price_lists', ['status_check' => "status IN ('activa','inactiva')"]);

        // §3.1: la agencia de Finanzas gana convenio (RUC, contacto, lista, vigencia, moneda de la línea) y motivo de bloqueo.
        Schema::table('channel_b2b_agencies', function (Blueprint $table) {
            $table->char('ruc', 11)->nullable();
            $table->string('contact_name', 150)->nullable();
            $table->string('booking_email', 190)->nullable();
            $table->foreignUuid('price_list_id')->nullable()->constrained('channel_b2b_price_lists');
            $table->char('credit_currency', 3)->default('PEN');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->string('block_reason', 30)->nullable();
            $table->string('block_note', 300)->nullable();
            $table->softDeletesTz();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'price_list_id']);
            $table->index(['tenant_id', 'client_id']);
        });
        DB::statement('CREATE UNIQUE INDEX channel_b2b_agencies_ruc_unique ON channel_b2b_agencies (tenant_id, ruc) WHERE deleted_at IS NULL AND ruc IS NOT NULL');
        $checks('channel_b2b_agencies', [
            'ruc_check' => "ruc IS NULL OR ruc ~ '^[0-9]{11}$'",
            'commission_pct_check' => 'commission_pct BETWEEN 0 AND 30',
            'validity_check' => 'valid_to IS NULL OR valid_from IS NULL OR valid_to >= valid_from',
            'block_reason_check' => "block_reason IS NULL OR block_reason IN ('manual','credito_excedido')",
            'credit_currency_check' => "credit_currency IN ('PEN','USD')",
        ]);

        // §3.3: tarifa por agencia ligada a la agencia real y a la lista; "Quitar" = soft delete.
        Schema::table('catalog_product_b2b_rates', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'agency_id']);
            $table->foreign('agency_id')->references('id')->on('channel_b2b_agencies');
            $table->foreignUuid('price_list_id')->nullable()->constrained('channel_b2b_price_lists');
            $table->softDeletesTz();
            $table->index(['tenant_id', 'agency_id']);
        });
        DB::statement('CREATE UNIQUE INDEX catalog_product_b2b_rates_agency_unique ON catalog_product_b2b_rates (product_id, agency_id) WHERE deleted_at IS NULL');

        // §3.4: libro de la línea de crédito (inmutable); credit_used = suma de movimientos.
        Schema::create('channel_b2b_credit_movements', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('agency_id')->constrained('channel_b2b_agencies');
            $table->string('movement_type', 30);
            $table->foreignUuid('reservation_id')->nullable()->index()->constrained('booking_reservations');
            $table->foreignUuid('payment_id')->nullable()->index()->constrained('fin_payments');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->decimal('source_amount', 14, 2)->nullable();
            $table->char('source_currency', 3)->nullable();
            $table->decimal('fx_rate', 12, 6)->nullable();
            $table->decimal('balance_after', 14, 2);
            $table->string('note', 250)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at');
            $table->index(['tenant_id', 'agency_id', 'created_at']);
        });
        $checks('channel_b2b_credit_movements', ['type_check' => "movement_type IN ('cargo_reserva','pago_credito','pago_aplicado','reversion_pago','anulacion_reserva','ajuste')"]);

        // §3.5
        Schema::create('channel_b2b_settlement_requests', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 30);
            $table->foreignUuid('agency_id')->index()->constrained('channel_b2b_agencies');
            $table->decimal('amount_pen', 14, 2);
            $table->decimal('fx_rate', 12, 6);
            $table->jsonb('commission_ids');
            $table->string('status', 15)->default('solicitada');
            $table->foreignUuid('requested_by')->constrained('iam_users');
            $audit($table);
            $table->unique(['tenant_id', 'code']);
        });
        $checks('channel_b2b_settlement_requests', ['status_check' => "status IN ('solicitada','aprobada','rechazada','pagada')"]);
        Schema::table('fin_commissions', function (Blueprint $table) {
            $table->foreignUuid('settlement_request_id')->nullable()->index()->constrained('channel_b2b_settlement_requests');
        });
        Schema::table('fin_payments', function (Blueprint $table) {
            $table->boolean('reported_by_agency')->default(false);
        });

        // §3.6–3.8 pedidos web.
        Schema::create('channel_ecom_orders', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 30);
            $table->timestampTz('ordered_at');
            $table->foreignUuid('client_id')->index()->constrained('crm_clients');
            $table->string('customer_name', 150);
            $table->string('customer_email', 190);
            $table->string('customer_phone', 40)->nullable();
            $table->string('customer_country', 60)->nullable();
            $table->foreignUuid('product_id')->index()->constrained('catalog_products');
            $table->foreignUuid('departure_id')->nullable()->index()->constrained('catalog_product_departures');
            $table->date('service_date');
            $table->smallInteger('pax_adults');
            $table->smallInteger('pax_children')->default(0);
            $table->jsonb('config_snapshot')->default('[]');
            $table->jsonb('price_snapshot');
            $table->string('promo_code', 40)->nullable();
            $table->foreignUuid('promotion_id')->nullable()->constrained('catalog_product_promotions');
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->char('currency', 3);
            $table->decimal('cost_total', 14, 2)->default(0);
            $table->foreignUuid('reservation_id')->nullable()->unique()->constrained('booking_reservations');
            $table->foreignUuid('receivable_id')->nullable()->constrained('fin_receivables');
            $table->string('status', 20)->default('pago_pendiente');
            $table->timestampTz('hold_expires_at')->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->jsonb('buyer_snapshot');
            $table->jsonb('utm')->nullable();
            $table->char('access_token_hash', 64);
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'ordered_at']);
            $table->index(['tenant_id', 'customer_email']);
        });
        DB::statement('ALTER TABLE channel_ecom_orders ADD COLUMN pax_total smallint GENERATED ALWAYS AS (pax_adults + pax_children) STORED');
        DB::statement("CREATE INDEX channel_ecom_orders_hold_idx ON channel_ecom_orders (hold_expires_at) WHERE status IN ('pago_pendiente','pago_rechazado')");
        $checks('channel_ecom_orders', [
            'status_check' => "status IN ('pago_pendiente','pago_rechazado','confirmado','cancelado','expirado')",
            'pax_check' => 'pax_adults >= 1 AND pax_children >= 0',
            'currency_check' => "currency IN ('PEN','USD')",
        ]);
        Schema::create('channel_ecom_passengers', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('order_id')->index()->constrained('channel_ecom_orders')->restrictOnDelete();
            $table->smallInteger('position');
            $table->string('full_name', 150);
            $table->string('doc_type', 20)->nullable();
            $table->string('doc_number', 30);
            $table->char('pax_type', 3);
            $table->timestampsTz();
        });
        $checks('channel_ecom_passengers', ['pax_type_check' => "pax_type IN ('ADT','CHD')"]);
        Schema::create('channel_ecom_payment_attempts', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('order_id')->index()->constrained('channel_ecom_orders');
            $table->timestampTz('attempted_at');
            $table->string('card_masked', 20);
            $table->string('result', 20);
            $table->string('gateway_code', 20)->nullable();
            $table->string('message', 200)->nullable();
            $table->string('gateway_txn_id', 80)->nullable();
            $table->foreignUuid('payment_id')->nullable()->constrained('fin_payments');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'gateway_txn_id']);
        });
        $checks('channel_ecom_payment_attempts', ['result_check' => "result IN ('aprobado','rechazado')"]);
        Schema::table('booking_reservations', function (Blueprint $table) {
            $table->foreign('agency_id')->references('id')->on('channel_b2b_agencies');
            $table->index(['tenant_id', 'agency_id']);
            $table->foreignUuid('ecom_order_id')->nullable()->constrained('channel_ecom_orders');
            $table->jsonb('b2b_terms')->nullable();
        });

        // §3.9: promociones de e-commerce (cantidad mínima configurable) con borrado lógico.
        Schema::table('catalog_product_promotions', function (Blueprint $table) {
            $table->smallInteger('min_pax')->nullable();
            $table->softDeletesTz();
        });
        DB::statement('DROP INDEX IF EXISTS catalog_product_promotions_code_unique');
        DB::statement("CREATE UNIQUE INDEX catalog_product_promotions_code_unique ON catalog_product_promotions (tenant_id, promo_code) WHERE promo_code IS NOT NULL AND promo_code <> '' AND deleted_at IS NULL");

        // §3.10–3.11 CMS.
        Schema::create('cms_contents', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 30);
            $table->string('title', 200);
            $table->string('type', 30);
            $table->string('channel', 40);
            $table->string('slug', 60);
            $table->text('body');
            $table->string('seo_title', 60)->nullable();
            $table->string('seo_description', 160)->nullable();
            $table->string('status', 20)->default('borrador');
            $table->foreignUuid('author_id')->constrained('iam_users');
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('unpublished_at')->nullable();
            $table->string('placement', 40)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'updated_at']);
        });
        DB::statement('CREATE UNIQUE INDEX cms_contents_slug_unique ON cms_contents (tenant_id, slug) WHERE deleted_at IS NULL');
        $checks('cms_contents', [
            'type_check' => "type IN ('Página','Artículo','Banner','Página legal','Landing')",
            'channel_check' => "channel IN ('Web','Web / E-commerce','Web / Portal','Portal cliente','Portal pasajero','App pasajero')",
            'status_check' => "status IN ('borrador','en_revision','aprobado','publicado','despublicado')",
            'slug_check' => "slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'",
            'validity_check' => 'valid_to IS NULL OR valid_from IS NULL OR valid_to >= valid_from',
        ]);
        Schema::create('cms_content_events', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('content_id')->index()->constrained('cms_contents');
            $table->timestampTz('occurred_at');
            $table->foreignUuid('user_id')->constrained('iam_users');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->string('message', 300);
            $table->string('reason', 300)->nullable();
        });

        // §3.12 tickets de portal.
        Schema::create('channel_support_tickets', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 30);
            $table->foreignUuid('client_id')->index()->constrained('crm_clients');
            $table->foreignUuid('reservation_id')->nullable()->index()->constrained('booking_reservations');
            $table->string('source', 30);
            $table->string('subject', 200);
            $table->string('category', 40);
            $table->string('topic', 40)->nullable();
            $table->string('priority', 10)->default('media');
            $table->text('message');
            $table->string('status', 20)->default('abierta');
            $table->foreignUuid('owner_id')->constrained('iam_users');
            $table->foreignUuid('created_by_user_id')->constrained('iam_users');
            $table->timestampTz('first_response_due_at')->nullable();
            $audit($table);
            $table->unique(['tenant_id', 'code']);
        });
        $checks('channel_support_tickets', [
            'source_check' => "source IN ('portal_cliente','portal_pasajero')",
            'priority_check' => "priority IN ('baja','media','alta')",
            'status_check' => "status IN ('abierta','en_proceso','resuelta','cerrada')",
        ]);
        Schema::create('channel_support_ticket_events', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('ticket_id')->index()->constrained('channel_support_tickets');
            $table->timestampTz('occurred_at');
            $table->uuid('user_id')->nullable();
            $table->string('message', 300);
        });

        // §3.13 acceso del pasajero por enlace.
        Schema::create('channel_portal_access_links', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('reservation_id')->index()->constrained('booking_reservations');
            $table->foreignUuid('passenger_id')->nullable()->constrained('booking_passengers');
            $table->string('email', 190);
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampsTz();
        });

        // §3.14 confirmación de lectura de alertas + texto público de la incidencia (§3.15).
        Schema::create('ops_incident_pax_acks', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('incident_id')->constrained('ops_incidents');
            $table->foreignUuid('user_id')->constrained('iam_users');
            $table->timestampTz('acknowledged_at');
            $table->unique(['incident_id', 'user_id']);
        });
        Schema::table('ops_incidents', function (Blueprint $table) {
            $table->string('public_title', 200)->nullable();
            $table->text('public_message')->nullable();
        });

        // §3.16 v2.1 comentarios del pasajero.
        Schema::create('channel_pax_feedback', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 30);
            $table->foreignUuid('reservation_id')->index()->constrained('booking_reservations');
            $table->foreignUuid('reservation_service_id')->nullable()->constrained('booking_services');
            $table->foreignUuid('user_id')->constrained('iam_users');
            $table->string('type', 20);
            $table->smallInteger('rating')->nullable();
            $table->text('text');
            $table->string('channel', 20)->default('Portal pasajero');
            $table->string('contact_email', 190)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('status', 20)->default('recibido');
            $table->text('response')->nullable();
            $table->timestampTz('responded_at')->nullable();
            $table->foreignUuid('ticket_id')->nullable()->constrained('channel_support_tickets');
            $audit($table);
            $table->unique(['tenant_id', 'code']);
        });
        $checks('channel_pax_feedback', [
            'type_check' => "type IN ('comentario','sugerencia','felicitacion','reclamo','incidencia')",
            'rating_check' => 'rating IS NULL OR rating BETWEEN 1 AND 5',
            'status_check' => "status IN ('recibido','en_revision','respondido','cerrado')",
        ]);
        // Voucher v1 emitido al reservar desde el portal B2B (B2B-BR-11).
        $checks('ops_vouchers', ['origin_check' => "origin IN ('operaciones','ecommerce','b2b')"]);

        foreach (self::TABLES as $table) {
            Rls::tenantTable($table);
        }
        foreach (DB::table('tenants')->get(['id']) as $tenant) {
            ChannelProvisioner::seedTenant($tenant->id, DB::connection());
        }
    }

    public function down(): void
    {
        Schema::table('ops_incidents', fn (Blueprint $t) => $t->dropColumn(['public_title', 'public_message']));
        Schema::table('booking_reservations', function (Blueprint $t) {
            $t->dropForeign(['agency_id']);
            $t->dropConstrainedForeignId('ecom_order_id');
            $t->dropColumn('b2b_terms');
        });
        Schema::table('fin_commissions', fn (Blueprint $t) => $t->dropConstrainedForeignId('settlement_request_id'));
        Schema::table('fin_payments', fn (Blueprint $t) => $t->dropColumn('reported_by_agency'));
        foreach (array_reverse(self::TABLES) as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table} CASCADE");
        }
    }
};
