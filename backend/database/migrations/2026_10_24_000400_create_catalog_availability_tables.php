<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Spec 02 §3.9: salidas programadas y holds de disponibilidad (PROC-020).
    public function up(): void
    {
        Schema::create('catalog_product_departures', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('ref', 20);
            $table->foreignUuid('product_id')->constrained('catalog_products');
            $table->date('departure_date');
            $table->time('departure_time');
            $table->smallInteger('capacity');
            $table->smallInteger('sold')->default(0);
            $table->string('status', 12)->default('abierta');
            $table->foreignUuid('provider_id')->nullable()->index()->constrained('catalog_providers');
            // ops_guides / ops_vehicles llegan con el módulo de Operaciones.
            $table->uuid('guide_id')->nullable();
            $table->uuid('vehicle_id')->nullable();
            $table->string('meeting_point', 160);
            $table->string('return_point', 160)->nullable();
            $table->text('notes')->nullable();
            $table->integer('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'ref']);
            $table->index(['tenant_id', 'departure_date']);
            $table->index(['product_id', 'departure_date', 'departure_time']);
            $table->index(['tenant_id', 'status']);
        });
        foreach ([
            'capacity_check CHECK (capacity BETWEEN 1 AND 60)',
            'sold_check CHECK (sold >= 0 AND sold <= capacity)',
            "status_check CHECK (status IN ('abierta','completa','cerrada','cancelada'))",
        ] as $c) {
            DB::statement("ALTER TABLE catalog_product_departures ADD CONSTRAINT catalog_product_departures_{$c}");
        }

        Schema::create('catalog_availability_holds', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('ref', 20);
            $table->foreignUuid('departure_id')->constrained('catalog_product_departures');
            $table->smallInteger('quantity');
            $table->string('reference', 60);
            $table->string('reference_type', 20)->nullable();
            $table->uuid('reference_id')->nullable();
            $table->string('status', 12);
            $table->timestampTz('expires_at');
            $table->text('released_reason')->nullable();
            $table->text('notes')->nullable();
            $table->integer('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'ref']);
            $table->index(['departure_id', 'status']);
            $table->index(['tenant_id', 'status', 'expires_at']);
        });
        foreach ([
            'quantity_check CHECK (quantity >= 1)',
            "status_check CHECK (status IN ('hold','confirmada','liberada','expirada','consumida'))",
            "release_check CHECK (status <> 'liberada' OR released_reason IS NOT NULL)",
        ] as $c) {
            DB::statement("ALTER TABLE catalog_availability_holds ADD CONSTRAINT catalog_availability_holds_{$c}");
        }

        Rls::tenantTable('catalog_product_departures');
        Rls::tenantTable('catalog_availability_holds');
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_availability_holds');
        Schema::dropIfExists('catalog_product_departures');
    }
};
