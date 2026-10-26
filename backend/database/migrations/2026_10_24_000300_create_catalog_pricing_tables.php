<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Spec 02 §3.8: temporadas, tarifas, condiciones y reglas de pricing.
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

        Schema::create('catalog_seasons', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('ref', 20);
            $table->string('name', 120);
            $table->string('type', 20);
            $table->date('date_from');
            $table->date('date_to');
            $table->smallInteger('priority')->default(10);
            $table->decimal('factor', 4, 2)->default(1);
            $table->string('status', 10)->default('activa');
            $audit($table);
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'ref']);
        });
        $checks('catalog_seasons', [
            'type_check' => "type IN ('Alta','Media','Baja','Feriado','Fecha especial','Personalizada')",
            'dates_check' => 'date_to >= date_from',
            'priority_check' => 'priority >= 0',
            'factor_check' => 'factor > 0 AND factor <= 3',
            'status_check' => "status IN ('activa','inactiva')",
        ]);
        // PROC-019 / CAT-BR-17: dos temporadas activas con igual prioridad no pueden solaparse.
        DB::statement("ALTER TABLE catalog_seasons ADD CONSTRAINT catalog_seasons_no_overlap EXCLUDE USING gist (tenant_id WITH =, priority WITH =, daterange(date_from, date_to, '[]') WITH &&) WHERE (status = 'activa' AND deleted_at IS NULL)");

        Schema::create('catalog_product_seasons', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->foreignUuid('product_id')->index()->constrained('catalog_products')->cascadeOnDelete();
            $table->foreignUuid('season_id')->index()->constrained('catalog_seasons');
            $audit($table);
            $table->unique(['product_id', 'season_id']);
        });

        Schema::create('catalog_rates', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('ref', 20);
            $table->foreignUuid('product_id')->constrained('catalog_products');
            $table->string('rate_type', 15);
            $table->string('segment', 10);
            $table->string('channel', 12);
            $table->char('currency', 3);
            $table->string('price_basis', 15)->default('Por persona');
            $table->decimal('cost', 14, 2);
            $table->decimal('price', 14, 2);
            $table->decimal('min_price', 14, 2)->nullable();
            $table->decimal('max_price', 14, 2)->nullable();
            $table->date('valid_from');
            $table->date('valid_to');
            $table->foreignUuid('season_id')->nullable()->index()->constrained('catalog_seasons');
            $table->string('conditions', 300)->nullable();
            $table->string('status', 10)->default('vigente');
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique(['tenant_id', 'ref']);
            $table->index(['tenant_id', 'product_id']);
            $table->index(['tenant_id', 'valid_to']);
            $table->index(['product_id', 'segment', 'channel', 'valid_from', 'valid_to']);
        });
        $checks('catalog_rates', [
            'type_check' => "rate_type IN ('Proveedor','Costo','Venta','Retail','B2B','Mayorista','Corporativa','VIP','Promocional','Especial','Grupal')",
            'segment_check' => "segment IN ('Adulto','Niño','Infante','Pasajero','Grupo')",
            'channel_check' => "channel IN ('Directo','B2B','Corporativo','E-commerce','Mayorista','Todos')",
            'currency_check' => "currency IN ('PEN','USD')",
            'basis_check' => "price_basis IN ('Por persona','Precio fijo','Por grupo','Por habitación','Por unidad')",
            'cost_check' => 'cost >= 0',
            'price_check' => 'price > 0',
            'min_check' => 'min_price IS NULL OR price >= min_price',
            'max_check' => 'max_price IS NULL OR price <= max_price',
            'validity_check' => 'valid_to >= valid_from',
            'status_check' => "status IN ('vigente','inactiva')",
        ]);

        Schema::create('catalog_rate_conditions', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->foreignUuid('rate_id')->index()->constrained('catalog_rates')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('operator', 4);
            $table->jsonb('value');
            $audit($table);
        });
        $checks('catalog_rate_conditions', [
            'kind_check' => "kind IN ('weekday','min_pax','max_pax','min_age','max_age','advance_days_min','advance_days_max','customer_type','occupancy','duration')",
        ]);

        Schema::create('catalog_price_rules', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('ref', 20);
            $table->string('name', 120);
            $table->string('kind', 20);
            $table->decimal('value', 12, 4);
            $table->string('scope', 200);
            $table->string('channel', 12)->nullable();
            $table->smallInteger('min_pax')->nullable();
            $table->string('customer_residency', 10)->nullable();
            $table->foreignUuid('product_id')->nullable()->constrained('catalog_products');
            $table->char('currency', 3)->nullable();
            $table->smallInteger('priority')->default(100);
            $table->string('status', 10)->default('activa');
            $audit($table);
            $table->unique(['tenant_id', 'ref']);
        });
        $checks('catalog_price_rules', [
            'kind_check' => "kind IN ('Markup %','Markup fijo','Descuento %','Descuento fijo','Impuesto %','Comisión %','Redondeo','Precio mínimo','Precio máximo')",
            'channel_check' => "channel IN ('Directo','B2B','Corporativo','E-commerce','Mayorista','Todos')",
            'residency_check' => "customer_residency IN ('dom','nodom')",
            'currency_check' => "currency IN ('PEN','USD')",
            'status_check' => "status IN ('activa','inactiva')",
        ]);

        foreach (['catalog_seasons', 'catalog_rates', 'catalog_price_rules'] as $t) {
            Rls::tenantTable($t);
        }
        foreach (['catalog_product_seasons', 'catalog_rate_conditions'] as $t) {
            Rls::tenantTable($t, ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
        }
        // Temporadas: borrado lógico (deleted_at); sin DELETE físico.
    }

    public function down(): void
    {
        foreach (['catalog_price_rules', 'catalog_rate_conditions', 'catalog_rates', 'catalog_product_seasons', 'catalog_seasons'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
