<?php

use App\Domain\Bookings\BookingRulesProvisioner;
use App\Domain\Bookings\RuleCatalog;
use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tablas por tenant con borrado físico de filas hijas (reemplazo completo desde el Product 360). */
    private const DELETABLE = ['catalog_product_rule_overrides', 'catalog_accommodation_legs', 'catalog_accommodation_hotels', 'catalog_accommodation_room_types', 'catalog_pickup_points', 'catalog_pax_rules'];

    private const TABLES = ['sys_booking_rules', 'catalog_availability_ranges', 'catalog_product_addons', 'catalog_payment_policies', ...self::DELETABLE];

    // Spec 16 §3: centro de reglas, rangos de disponibilidad y configuración comercial por producto (motor de reservas).
    // Las listas cortas (días, canales, tipos de pasajero, modalidades) van en jsonb con CHECK de cardinalidad.
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        $base = function (Blueprint $table, bool $product = true) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            if ($product) {
                $table->foreignUuid('product_id')->constrained('catalog_products')->cascadeOnDelete();
                $table->index('product_id');
            }
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

        // §3.1 catálogo de plataforma (sin tenant) y centro de reglas por tenant.
        Schema::create('platform_booking_rule_templates', function (Blueprint $table) {
            $table->string('rule_key', 40)->primary();
            $table->string('group_name', 40);
            $table->string('name', 120);
            $table->text('description');
            $table->boolean('enabled');
            $table->jsonb('params')->default('{}');
            $table->jsonb('scope')->default('[]');
            $table->boolean('product_override');
            $table->jsonb('params_schema')->default('{}');
            $table->smallInteger('sort_order');
        });
        DB::table('platform_booking_rule_templates')->insert(RuleCatalog::templates());

        Schema::create('sys_booking_rules', function (Blueprint $table) use ($base, $audit) {
            $base($table, false);
            $table->string('rule_key', 40);
            $table->string('group_name', 40);
            $table->string('name', 120);
            $table->text('description');
            $table->boolean('enabled');
            $table->jsonb('params')->default('{}');
            $table->jsonb('scope')->default('[]');
            $table->boolean('product_override')->default(true);
            $table->boolean('is_system')->default(false);
            $table->jsonb('params_schema')->default('{}');
            $table->smallInteger('sort_order')->default(100);
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique(['tenant_id', 'rule_key']);
        });
        $checks('sys_booking_rules', [
            'key_check' => "rule_key ~ '^R-[A-Z0-9-]{2,37}$'",
            'group_check' => "group_name IN ('".implode("','", RuleCatalog::GROUPS)."')",
        ]);

        // §3.2 override por producto.
        Schema::create('catalog_product_rule_overrides', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('rule_key', 40);
            $table->boolean('enabled')->nullable();
            $table->jsonb('params')->default('{}');
            $audit($table);
            $table->unique(['tenant_id', 'product_id', 'rule_key']);
        });

        // §3.3 rangos de disponibilidad con exclusión de solapes del mismo tipo (CAT-AV-01).
        Schema::create('catalog_availability_ranges', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->date('date_from');
            $table->date('date_to');
            $table->string('status', 20);
            $table->integer('capacity_per_day')->default(0);
            $table->string('note', 200)->nullable();
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->timestampTz('deleted_at')->nullable();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'product_id', 'date_from', 'date_to']);
        });
        $checks('catalog_availability_ranges', [
            'dates_check' => 'date_to >= date_from',
            'span_check' => 'date_to - date_from <= 730',
            'status_check' => "status IN ('disponible','cerrado','agotado')",
            'capacity_check' => "(status = 'disponible' AND capacity_per_day BETWEEN 1 AND 500) OR (status <> 'disponible' AND capacity_per_day = 0)",
        ]);
        DB::statement("CREATE INDEX catalog_availability_ranges_span ON catalog_availability_ranges USING gist (product_id, daterange(date_from, date_to, '[]')) WHERE deleted_at IS NULL");
        DB::statement("ALTER TABLE catalog_availability_ranges ADD CONSTRAINT ex_avail_open EXCLUDE USING gist (product_id WITH =, daterange(date_from, date_to, '[]') WITH &&) WHERE (status = 'disponible' AND deleted_at IS NULL)");
        DB::statement("ALTER TABLE catalog_availability_ranges ADD CONSTRAINT ex_avail_closed EXCLUDE USING gist (product_id WITH =, daterange(date_from, date_to, '[]') WITH &&) WHERE (status IN ('cerrado','agotado') AND deleted_at IS NULL)");

        // §3.3 operación, §3.6 cabecera de alojamiento y §3.8 cabecera de recojo en la ficha del producto.
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->jsonb('operating_weekdays')->default('[0,1,2,3,4,5,6]');
            $table->integer('cutoff_hours')->nullable();
            $table->boolean('accommodation_enabled')->default(false);
            $table->boolean('accommodation_optional')->default(true);
            $table->char('accommodation_currency', 3)->nullable();
            $table->text('accommodation_note')->nullable();
            $table->boolean('pickup_required')->default(false);
            $table->boolean('pickup_ask_flight')->default(false);
        });
        $checks('catalog_products', [
            'weekdays_check' => "jsonb_typeof(operating_weekdays) = 'array' AND jsonb_array_length(operating_weekdays) BETWEEN 1 AND 7",
            'cutoff_check' => 'cutoff_hours IS NULL OR cutoff_hours BETWEEN 0 AND 8760',
            'acc_currency_check' => "accommodation_currency IS NULL OR accommodation_currency IN ('PEN','USD')",
        ]);

        // §3.4 reglas de pasajeros (1:1).
        Schema::create('catalog_pax_rules', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->smallInteger('adult_min_age');
            $table->smallInteger('child_min_age');
            $table->smallInteger('child_max_age');
            $table->smallInteger('infant_max_age');
            $table->boolean('child_pays')->default(true);
            $table->decimal('child_price_pct', 5, 2);
            $table->boolean('infant_allowed')->default(true);
            $table->boolean('infant_pays')->default(false);
            $table->smallInteger('max_children_per_adult');
            $table->smallInteger('max_infants_per_adult');
            $table->smallInteger('min_adults');
            $table->text('note')->nullable();
            $audit($table);
            $table->unique(['tenant_id', 'product_id']);
        });
        $checks('catalog_pax_rules', [
            'bands_check' => 'infant_max_age >= 0 AND infant_max_age < child_min_age AND child_min_age <= child_max_age AND child_max_age < adult_min_age',
            'pct_check' => 'child_price_pct BETWEEN 0 AND 100',
            'max_check' => 'max_children_per_adult BETWEEN 0 AND 10 AND max_infants_per_adult BETWEEN 0 AND 5 AND min_adults BETWEEN 0 AND 10',
        ]);

        // §3.5 adicionales.
        Schema::create('catalog_product_addons', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->string('name', 150);
            $table->string('category', 20);
            $table->decimal('price', 14, 2);
            $table->char('currency', 3);
            $table->string('charge_per', 10);
            $table->jsonb('applies_to')->default('["ADT","CHD"]');
            $table->string('stock_note', 200)->nullable();
            $table->foreignUuid('provider_id')->nullable()->constrained('catalog_providers');
            $table->foreignUuid('service_id')->nullable()->constrained('catalog_services');
            $table->boolean('is_optional')->default(true);
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->timestampTz('deleted_at')->nullable();
            $table->unique(['tenant_id', 'code']);
        });
        $checks('catalog_product_addons', [
            'category_check' => "category IN ('Museo','Entrada','Alimentación','Transporte','Extensión','Seguro','Experiencia','Servicio','Otro')",
            'price_check' => 'price >= 0',
            'currency_check' => "currency IN ('PEN','USD')",
            'charge_check' => "charge_per IN ('pax','reserva')",
            'applies_check' => "jsonb_typeof(applies_to) = 'array' AND jsonb_array_length(applies_to) BETWEEN 1 AND 3 AND applies_to <@ '[\"ADT\",\"CHD\",\"INF\"]'::jsonb",
        ]);
        DB::statement('CREATE UNIQUE INDEX catalog_product_addons_name_unique ON catalog_product_addons (tenant_id, product_id, lower(name)) WHERE deleted_at IS NULL');

        // §3.6 alojamiento por tramos.
        Schema::create('catalog_accommodation_legs', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->smallInteger('sequence');
            $table->string('city', 80);
            $table->foreignUuid('destination_id')->nullable()->constrained('catalog_destinations');
            $table->smallInteger('nights');
            $audit($table);
            $table->unique(['tenant_id', 'product_id', 'code']);
        });
        $checks('catalog_accommodation_legs', ['nights_check' => 'nights BETWEEN 1 AND 30']);
        DB::statement('ALTER TABLE catalog_accommodation_legs ADD CONSTRAINT catalog_accommodation_legs_sequence_unique UNIQUE (product_id, sequence) DEFERRABLE INITIALLY DEFERRED');

        Schema::create('catalog_accommodation_hotels', function (Blueprint $table) use ($base, $audit) {
            $base($table, false);
            $table->foreignUuid('leg_id')->constrained('catalog_accommodation_legs')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->string('category', 30);
            $table->foreignUuid('provider_id')->nullable()->constrained('catalog_providers');
            $audit($table);
            $table->index('leg_id');
        });
        $checks('catalog_accommodation_hotels', ['category_check' => "category IN ('3 estrellas','4 estrellas','5 estrellas','Hostal','Lodge','Boutique')"]);
        DB::statement('CREATE UNIQUE INDEX catalog_accommodation_hotels_name_unique ON catalog_accommodation_hotels (leg_id, lower(name))');

        Schema::create('catalog_accommodation_room_types', function (Blueprint $table) use ($base, $audit) {
            $base($table, false);
            $table->foreignUuid('hotel_id')->constrained('catalog_accommodation_hotels')->cascadeOnDelete();
            $table->string('room_type', 20);
            $table->smallInteger('capacity');
            $table->decimal('price_per_night', 14, 2);
            $audit($table);
            $table->unique(['hotel_id', 'room_type']);
        });
        $checks('catalog_accommodation_room_types', [
            'type_check' => "room_type IN ('Simple','Doble','Matrimonial','Triple','Familiar','Suite')",
            'capacity_check' => 'capacity BETWEEN 1 AND 8',
            'price_check' => 'price_per_night > 0',
        ]);

        // §3.7 política de pago (1:1); «pagar después» lo gobierna R-PAY-LATER.
        Schema::create('catalog_payment_policies', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->jsonb('modes')->default('["total","adelanto"]');
            $table->decimal('deposit_pct', 5, 2)->nullable();
            $table->smallInteger('balance_due_days')->nullable();
            $table->text('note')->nullable();
            $audit($table);
            $table->unique(['tenant_id', 'product_id']);
        });
        $checks('catalog_payment_policies', [
            'modes_check' => "jsonb_typeof(modes) = 'array' AND jsonb_array_length(modes) BETWEEN 1 AND 2 AND modes <@ '[\"total\",\"adelanto\"]'::jsonb",
            'pct_check' => 'deposit_pct IS NULL OR deposit_pct BETWEEN 1 AND 99',
            'days_check' => 'balance_due_days IS NULL OR balance_due_days BETWEEN 0 AND 180',
        ]);

        // §3.8 puntos de recojo.
        Schema::create('catalog_pickup_points', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->smallInteger('sequence');
            $table->string('label', 150);
            $table->string('kind', 20);
            $table->boolean('requires_address')->default(false);
            $audit($table);
        });
        $checks('catalog_pickup_points', ['kind_check' => "kind IN ('aeropuerto','hotel','punto_encuentro','terminal','por_cuenta')"]);
        DB::statement('CREATE UNIQUE INDEX catalog_pickup_points_label_unique ON catalog_pickup_points (product_id, lower(label))');

        // §4.4 holds por fecha para productos con rangos (sin salida programada).
        DB::statement('ALTER TABLE catalog_availability_holds ALTER COLUMN departure_id DROP NOT NULL');
        Schema::table('catalog_availability_holds', function (Blueprint $table) {
            $table->foreignUuid('product_id')->nullable()->constrained('catalog_products');
            $table->date('service_date')->nullable();
            $table->index(['product_id', 'service_date', 'status']);
        });
        $checks('catalog_availability_holds', ['target_check' => 'departure_id IS NOT NULL OR (product_id IS NOT NULL AND service_date IS NOT NULL)']);

        // §3.9 snapshot del motor en la reserva.
        Schema::table('booking_reservations', fn (Blueprint $table) => $table->jsonb('booking')->nullable());
        // Vendidos por producto y fecha (AvailabilityCalculator::sold).
        DB::statement('CREATE INDEX booking_services_product_date ON booking_services (product_id, service_date) WHERE product_id IS NOT NULL');

        Rls::grantApp('platform_booking_rule_templates', ['SELECT']);
        Rls::grantPlatform('platform_booking_rule_templates', ['SELECT', 'INSERT', 'UPDATE']);
        foreach (self::TABLES as $table) {
            Rls::tenantTable($table);
        }
        foreach (self::DELETABLE as $table) {
            DB::statement("GRANT DELETE ON {$table} TO ".config('turismo.db_roles.app'));
        }

        foreach (DB::table('tenants')->get(['id']) as $tenant) {
            BookingRulesProvisioner::seedTenant($tenant->id, DB::connection());
        }
    }

    public function down(): void
    {
        DB::table('sys_sequences')->whereIn('doc_type', array_keys(BookingRulesProvisioner::SEQUENCES))->delete();
        DB::statement('DROP INDEX IF EXISTS booking_services_product_date');
        Schema::table('booking_reservations', fn (Blueprint $table) => $table->dropColumn('booking'));
        DB::statement('ALTER TABLE catalog_availability_holds DROP CONSTRAINT catalog_availability_holds_target_check');
        Schema::table('catalog_availability_holds', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropColumn(['product_id', 'service_date']);
        });
        foreach (['catalog_pickup_points', 'catalog_payment_policies', 'catalog_accommodation_room_types', 'catalog_accommodation_hotels', 'catalog_accommodation_legs',
            'catalog_product_addons', 'catalog_pax_rules', 'catalog_availability_ranges', 'catalog_product_rule_overrides', 'sys_booking_rules', 'platform_booking_rule_templates'] as $table) {
            Schema::dropIfExists($table);
        }
        foreach (['weekdays_check', 'cutoff_check', 'acc_currency_check'] as $c) {
            DB::statement("ALTER TABLE catalog_products DROP CONSTRAINT catalog_products_{$c}");
        }
        Schema::table('catalog_products', fn (Blueprint $table) => $table->dropColumn(['operating_weekdays', 'cutoff_hours', 'accommodation_enabled', 'accommodation_optional',
            'accommodation_currency', 'accommodation_note', 'pickup_required', 'pickup_ask_flight']));
    }
};
