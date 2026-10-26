<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SATELLITES = [
        'catalog_product_destinations', 'catalog_product_items', 'catalog_itineraries', 'catalog_itinerary_days', 'catalog_itinerary_items',
        'catalog_product_inclusions', 'catalog_product_requirements', 'catalog_product_policies', 'catalog_product_related',
        'catalog_product_b2b_rates', 'catalog_product_promotions', 'catalog_product_media', 'catalog_product_faqs',
        'catalog_product_channels', 'catalog_product_config_groups', 'catalog_product_config_options', 'catalog_product_versions',
    ];

    // Spec 02 §3.6–§3.7: ficha maestra del producto y tablas satélite.
    public function up(): void
    {
        $id = function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
        };
        $audit = function (Blueprint $table) {
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
        };
        $child = function (Blueprint $table) use ($id, $audit) {
            $id($table);
            $table->foreignUuid('product_id')->index()->constrained('catalog_products')->cascadeOnDelete();
            $audit($table);
        };
        $checks = function (string $table, array $checks) {
            foreach ($checks as $name => $check) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$name} CHECK ({$check})");
            }
        };

        Schema::create('catalog_products', function (Blueprint $table) use ($id, $audit) {
            $id($table);
            $table->string('ref', 20);
            $table->string('code', 30);
            $table->string('sku', 40);
            $table->string('name', 160);
            $table->string('internal_name', 160);
            $table->foreignUuid('product_type_id')->index()->constrained('catalog_product_types');
            $table->foreignUuid('category_id')->index()->constrained('catalog_product_categories');
            $table->foreignUuid('subcategory_id')->nullable()->index()->constrained('catalog_product_subcategories');
            $table->foreignUuid('primary_destination_id')->index()->constrained('catalog_destinations');
            $table->string('status', 20)->default('DRAFT');
            $table->text('short_description')->nullable();
            $table->text('long_description')->nullable();
            $table->decimal('duration_value', 6, 1);
            $table->string('duration_unit', 10);
            $table->string('modality', 15);
            $table->foreignUuid('main_provider_id')->nullable()->index()->constrained('catalog_providers');
            $table->smallInteger('minimum_passengers')->default(1);
            $table->smallInteger('maximum_passengers')->default(20);
            $table->smallInteger('minimum_age')->default(0);
            $table->smallInteger('maximum_age')->default(99);
            $table->smallInteger('booking_advance_min_days')->default(0);
            $table->smallInteger('booking_advance_max_days')->default(365);
            $table->boolean('is_bookable')->default(true);
            $table->boolean('is_quoteable')->default(true);
            $table->boolean('is_b2b')->default(false);
            $table->boolean('is_ecommerce')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->decimal('markup_pct', 6, 2)->default(25);
            $table->string('availability_model', 20)->default('SIN_CONTROL');
            $table->integer('availability_capacity')->nullable();
            $table->string('availability_source', 10)->default('interna');
            $table->jsonb('features')->default('[]');
            $table->string('video_url', 500)->nullable();
            $table->string('seo_title', 120)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->string('slug', 160)->nullable();
            $table->uuid('current_version_id')->nullable();
            $table->jsonb('attributes')->default('{}');
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'ref']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });
        DB::statement('CREATE UNIQUE INDEX catalog_products_code_unique ON catalog_products (tenant_id, lower(code))');
        DB::statement('CREATE UNIQUE INDEX catalog_products_slug_unique ON catalog_products (tenant_id, slug) WHERE slug IS NOT NULL');
        DB::statement("CREATE INDEX catalog_products_search_trgm ON catalog_products USING gin (lower(name || ' ' || code || ' ' || sku) gin_trgm_ops)");
        $checks('catalog_products', [
            'status_check' => "status IN ('DRAFT','REVIEW','APPROVED','ACTIVE','SUSPENDED','INACTIVE','ARCHIVED')",
            'duration_check' => 'duration_value >= 1',
            'duration_unit_check' => "duration_unit IN ('horas','días','noches')",
            'modality_check' => "modality IN ('Privado','Compartido','Grupal')",
            'passengers_check' => 'minimum_passengers >= 1 AND maximum_passengers >= minimum_passengers',
            'age_check' => 'minimum_age >= 0 AND maximum_age >= minimum_age',
            'advance_check' => 'booking_advance_min_days >= 0 AND booking_advance_max_days >= booking_advance_min_days',
            'availability_model_check' => "availability_model IN ('SIN_CONTROL','CUPOS','CAPACIDAD','POR_PROVEEDOR','POR_ACTIVIDAD','POR_TRANSPORTE','POR_HABITACION','POR_SALIDA','POR_FECHA')",
            'availability_source_check' => "availability_source IN ('interna','externa')",
            'availability_capacity_check' => 'availability_capacity IS NULL OR availability_capacity >= 0',
            'slug_check' => "slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'",
            'markup_check' => 'markup_pct >= 0',
        ]);

        Schema::create('catalog_product_destinations', function (Blueprint $table) use ($child) {
            $child($table);
            $table->foreignUuid('destination_id')->index()->constrained('catalog_destinations');
            $table->string('role', 10)->default('secondary');
            $table->unique(['product_id', 'destination_id']);
        });
        $checks('catalog_product_destinations', ['role_check' => "role = 'secondary'"]);

        Schema::create('catalog_product_items', function (Blueprint $table) use ($child) {
            $child($table);
            $table->foreignUuid('service_id')->nullable()->index()->constrained('catalog_services');
            $table->foreignUuid('component_product_id')->nullable()->index()->constrained('catalog_products');
            $table->uuid('provider_id')->nullable();
            $table->decimal('quantity', 8, 2)->default(1);
            $table->string('unit', 20);
            $table->smallInteger('sort_order');
            $table->boolean('is_required')->default(true);
            $table->boolean('is_included')->default(true);
            $table->decimal('unit_cost_override', 14, 2)->nullable();
            $table->text('notes')->nullable();
            $table->index(['product_id', 'sort_order']);
        });
        $checks('catalog_product_items', [
            'source_check' => 'num_nonnulls(service_id, component_product_id) = 1',
            'self_check' => 'component_product_id IS NULL OR component_product_id <> product_id',
            'quantity_check' => 'quantity >= 1',
            'unit_check' => "unit IN ('pax','servicio','habitación','noche','vehículo','unidad')",
        ]);

        Schema::create('catalog_itineraries', function (Blueprint $table) use ($child) {
            $child($table);
            $table->string('name', 120)->default('Principal');
            $table->boolean('is_default')->default(true);
        });
        DB::statement('CREATE UNIQUE INDEX catalog_itineraries_default_unique ON catalog_itineraries (product_id) WHERE is_default');

        Schema::create('catalog_itinerary_days', function (Blueprint $table) use ($id, $audit) {
            $id($table);
            $table->foreignUuid('itinerary_id')->index()->constrained('catalog_itineraries')->cascadeOnDelete();
            $table->smallInteger('day_number');
            $table->string('title', 160);
            $audit($table);
            $table->unique(['itinerary_id', 'day_number'], 'catalog_itinerary_days_number_unique');
        });
        // Renumerar al quitar un día intercambia números: la unicidad se verifica al final de la transacción.
        DB::statement('ALTER TABLE catalog_itinerary_days DROP CONSTRAINT catalog_itinerary_days_number_unique');
        DB::statement('ALTER TABLE catalog_itinerary_days ADD CONSTRAINT catalog_itinerary_days_number_unique UNIQUE (itinerary_id, day_number) DEFERRABLE INITIALLY DEFERRED');
        $checks('catalog_itinerary_days', ['number_check' => 'day_number >= 1']);

        Schema::create('catalog_itinerary_items', function (Blueprint $table) use ($id, $audit) {
            $id($table);
            $table->foreignUuid('day_id')->index()->constrained('catalog_itinerary_days')->cascadeOnDelete();
            $table->time('start_time')->nullable();
            $table->string('activity', 200);
            $table->string('item_type', 20)->default('Actividad');
            $table->string('location', 160)->nullable();
            $table->foreignUuid('service_id')->nullable()->constrained('catalog_services');
            $table->text('note')->nullable();
            $table->string('image_ref', 300)->nullable();
            $table->decimal('cost', 14, 2)->default(0);
            $table->decimal('price', 14, 2)->default(0);
            $audit($table);
        });
        $checks('catalog_itinerary_items', [
            'type_check' => "item_type IN ('Traslado','Transporte','Tren','Hotel','Alimentación','Guía','Actividad','Entrada','Libre','Otro')",
            'amounts_check' => 'cost >= 0 AND price >= 0',
        ]);

        Schema::create('catalog_product_inclusions', function (Blueprint $table) use ($child) {
            $child($table);
            $table->string('kind', 10);
            $table->string('text', 200);
            $table->smallInteger('sort_order')->default(0);
        });
        DB::statement('CREATE UNIQUE INDEX catalog_product_inclusions_unique ON catalog_product_inclusions (product_id, kind, lower(text))');
        $checks('catalog_product_inclusions', ['kind_check' => "kind IN ('include','exclude')"]);

        Schema::create('catalog_product_requirements', function (Blueprint $table) use ($child) {
            $child($table);
            $table->string('category', 30)->nullable();
            $table->string('text', 300);
        });
        $checks('catalog_product_requirements', ['category_check' => "category IN ('Condición física','Documentos','Nacionalidad','Restricción','Recomendación','Equipamiento','Requisito especial')"]);

        Schema::create('catalog_product_policies', function (Blueprint $table) use ($child) {
            $child($table);
            $table->text('cancellation');
            $table->text('modification');
            $table->text('no_show');
            $table->text('penalties')->nullable();
            $table->text('refund');
            $table->text('date_change')->nullable();
            $table->string('advance_min', 40)->nullable();
            $table->string('advance_max', 40)->nullable();
            $table->unique('product_id');
        });

        Schema::create('catalog_product_related', function (Blueprint $table) use ($child) {
            $child($table);
            $table->foreignUuid('related_product_id')->index()->constrained('catalog_products');
            $table->string('relation', 20);
            $table->unique(['product_id', 'related_product_id']);
        });
        $checks('catalog_product_related', [
            'self_check' => 'related_product_id <> product_id',
            'relation_check' => "relation IN ('Complementario','Alternativo','Upgrade','Cross-sell','Upsell')",
        ]);

        // agency_id → channel_b2b_agencies cuando exista el módulo de canales B2B.
        Schema::create('catalog_product_b2b_rates', function (Blueprint $table) use ($child) {
            $child($table);
            $table->uuid('agency_id');
            $table->string('price_list', 80);
            $table->decimal('price', 14, 2);
            $table->char('currency', 3);
            $table->decimal('commission_pct', 5, 2)->default(10);
            $table->boolean('is_credit')->default(false);
            $table->date('valid_to');
            $table->string('conditions', 200)->nullable();
            $table->string('restrictions', 200)->nullable();
            $table->unique(['product_id', 'agency_id']);
        });
        $checks('catalog_product_b2b_rates', [
            'price_check' => 'price > 0',
            'currency_check' => "currency IN ('PEN','USD')",
            'commission_check' => 'commission_pct BETWEEN 0 AND 100',
        ]);

        Schema::create('catalog_product_promotions', function (Blueprint $table) use ($child) {
            $child($table);
            $table->string('ref', 20);
            $table->string('name', 120);
            $table->string('kind', 30);
            $table->decimal('value', 12, 2);
            $table->string('channel', 20)->default('Todos');
            $table->string('promo_code', 30)->nullable();
            $table->date('date_from');
            $table->date('date_to');
            $table->string('status', 10)->default('activa');
            $table->unique(['tenant_id', 'ref']);
        });
        DB::statement("CREATE UNIQUE INDEX catalog_product_promotions_code_unique ON catalog_product_promotions (tenant_id, promo_code) WHERE promo_code IS NOT NULL AND promo_code <> ''");
        $checks('catalog_product_promotions', [
            'kind_check' => "kind IN ('Precio promocional','Descuento fijo','Descuento %','Promoción por fecha','Descuento por cantidad','Promoción por temporada','Promoción por canal','Código promocional')",
            'channel_check' => "channel IN ('Todos','Directo','E-commerce','B2B','Corporativo')",
            'dates_check' => 'date_to >= date_from',
            'status_check' => "status IN ('activa','inactiva')",
            'value_check' => 'value > 0',
            'code_check' => 'promo_code IS NULL OR promo_code = upper(promo_code)',
        ]);

        Schema::create('catalog_product_media', function (Blueprint $table) use ($child) {
            $child($table);
            $table->string('kind', 10)->default('image');
            $table->string('storage_path', 400);
            $table->string('alt_text', 200);
            $table->boolean('is_main')->default(false);
            $table->smallInteger('sort_order')->default(0);
        });
        DB::statement('CREATE UNIQUE INDEX catalog_product_media_main_unique ON catalog_product_media (product_id) WHERE is_main');
        $checks('catalog_product_media', ['kind_check' => "kind IN ('image')"]);

        Schema::create('catalog_product_faqs', function (Blueprint $table) use ($child) {
            $child($table);
            $table->string('question', 300);
            $table->text('answer');
            $table->smallInteger('sort_order')->default(0);
        });

        Schema::create('catalog_product_channels', function (Blueprint $table) use ($child) {
            $child($table);
            $table->string('channel', 12);
            $table->boolean('is_published')->default(false);
            // Solicitado en el asistente: se publica efectivamente al activar (CAT-BR-04).
            $table->boolean('is_requested')->default(false);
            $table->timestampTz('published_at')->nullable();
            $table->uuid('published_by')->nullable();
            $table->unique(['product_id', 'channel']);
        });
        $checks('catalog_product_channels', [
            'channel_check' => "channel IN ('erp','crm','quotes','b2b','portal','portal_pax','cms','ecommerce','mobile')",
            'erp_check' => "channel <> 'erp' OR is_published",
        ]);

        Schema::create('catalog_product_config_groups', function (Blueprint $table) use ($child) {
            $child($table);
            $table->string('name', 60);
            $table->smallInteger('sort_order')->default(0);
        });
        DB::statement('CREATE UNIQUE INDEX catalog_product_config_groups_name_unique ON catalog_product_config_groups (product_id, lower(name))');

        Schema::create('catalog_product_config_options', function (Blueprint $table) use ($id, $audit) {
            $id($table);
            $table->foreignUuid('group_id')->index()->constrained('catalog_product_config_groups')->cascadeOnDelete();
            $table->string('label', 80);
            $table->decimal('price_delta', 14, 2)->default(0);
            $table->smallInteger('sort_order')->default(0);
            $audit($table);
        });

        Schema::create('catalog_product_versions', function (Blueprint $table) use ($child) {
            $child($table);
            $table->string('version', 10);
            $table->string('status', 15);
            $table->date('valid_from');
            $table->text('reason');
            $table->jsonb('snapshot');
            $table->unique(['product_id', 'version']);
        });
        DB::statement("CREATE UNIQUE INDEX catalog_product_versions_current_unique ON catalog_product_versions (product_id) WHERE status = 'vigente'");
        $checks('catalog_product_versions', ['status_check' => "status IN ('borrador','en_revision','vigente','historica')"]);
        // PRD-BR-017: las versiones son inmutables; solo cambia el estado.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION catalog_product_versions_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.snapshot IS DISTINCT FROM OLD.snapshot OR NEW.version IS DISTINCT FROM OLD.version
                   OR NEW.valid_from IS DISTINCT FROM OLD.valid_from OR NEW.reason IS DISTINCT FROM OLD.reason
                   OR NEW.product_id IS DISTINCT FROM OLD.product_id THEN
                    RAISE EXCEPTION 'Las versiones de producto son inmutables (PRD-BR-017)' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER catalog_product_versions_immutable BEFORE UPDATE ON catalog_product_versions FOR EACH ROW EXECUTE FUNCTION catalog_product_versions_immutable()');

        Schema::table('catalog_products', fn (Blueprint $table) => $table->foreign('current_version_id')->references('id')->on('catalog_product_versions'));

        Rls::tenantTable('catalog_products');
        foreach (self::SATELLITES as $t) {
            Rls::tenantTable($t, ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
        }
        // Versiones: sin DELETE.
        DB::statement(sprintf('REVOKE DELETE ON catalog_product_versions FROM %s', config('turismo.db_roles.app')));
    }

    public function down(): void
    {
        Schema::table('catalog_products', fn (Blueprint $table) => $table->dropForeign(['current_version_id']));
        foreach (array_reverse(self::SATELLITES) as $t) {
            Schema::dropIfExists($t);
        }
        Schema::dropIfExists('catalog_products');
        DB::statement('DROP FUNCTION IF EXISTS catalog_product_versions_immutable()');
    }
};
