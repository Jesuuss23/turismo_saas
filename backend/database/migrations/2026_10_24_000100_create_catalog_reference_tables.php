<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Spec 02 §3.1–§3.5: tipos, categorías, destinos, proveedores y servicios.
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        $base = function (Blueprint $table, bool $ref = true) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            if ($ref) {
                $table->string('ref', 20);
                $table->unique(['tenant_id', 'ref']);
            }
        };
        $audit = function (Blueprint $table) {
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
        };

        Schema::create('catalog_product_types', function (Blueprint $table) use ($base, $audit) {
            $base($table, false);
            $table->string('name', 60);
            $table->boolean('requires_itinerary')->default(false);
            $table->boolean('requires_components')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $audit($table);
            $table->softDeletesTz();
        });
        DB::statement('CREATE UNIQUE INDEX catalog_product_types_name_unique ON catalog_product_types (tenant_id, lower(name)) WHERE deleted_at IS NULL');

        Schema::create('catalog_product_categories', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('name', 80);
            $audit($table);
            $table->softDeletesTz();
        });
        DB::statement('CREATE UNIQUE INDEX catalog_product_categories_name_unique ON catalog_product_categories (tenant_id, lower(name)) WHERE deleted_at IS NULL');

        Schema::create('catalog_product_subcategories', function (Blueprint $table) use ($base, $audit) {
            $base($table, false);
            $table->foreignUuid('category_id')->index()->constrained('catalog_product_categories')->restrictOnDelete();
            $table->string('name', 80);
            $audit($table);
            $table->softDeletesTz();
        });
        DB::statement('CREATE UNIQUE INDEX catalog_product_subcategories_name_unique ON catalog_product_subcategories (category_id, lower(name)) WHERE deleted_at IS NULL');

        Schema::create('catalog_destinations', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->uuid('parent_id')->nullable()->index();
            $table->string('name', 120);
            $table->string('type', 20);
            $table->smallInteger('level');
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();
            $table->text('description')->nullable();
            // Ruta materializada de ids ("/id1/id2/") para consultar descendientes (CAT-BR-43).
            $table->text('path');
            $audit($table);
            $table->softDeletesTz();
        });
        Schema::table('catalog_destinations', fn (Blueprint $table) => $table->foreign('parent_id')->references('id')->on('catalog_destinations'));
        DB::statement("CREATE UNIQUE INDEX catalog_destinations_name_unique ON catalog_destinations (tenant_id, coalesce(parent_id, '00000000-0000-0000-0000-000000000000'::uuid), lower(name)) WHERE deleted_at IS NULL");
        DB::statement('CREATE INDEX catalog_destinations_path_index ON catalog_destinations (tenant_id, path text_pattern_ops)');
        foreach ([
            "type_check CHECK (type IN ('País','Región','Departamento','Provincia','Ciudad','Zona','Lugar','Atracción','Ruta'))",
            'level_check CHECK (level BETWEEN 0 AND 6)',
            "root_check CHECK ((parent_id IS NULL) = (type = 'País'))",
            'lat_check CHECK (lat BETWEEN -90 AND 90)',
            'lng_check CHECK (lng BETWEEN -180 AND 180)',
            'coords_check CHECK ((lat IS NULL) = (lng IS NULL))',
        ] as $c) {
            DB::statement("ALTER TABLE catalog_destinations ADD CONSTRAINT catalog_destinations_{$c}");
        }
        // CAT-BR-39: el nivel del hijo debe ser mayor que el del padre (respaldo del DestinationService).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION catalog_destinations_check_level() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE parent_level smallint;
            BEGIN
                IF NEW.parent_id IS NOT NULL THEN
                    SELECT level INTO parent_level FROM catalog_destinations WHERE id = NEW.parent_id;
                    IF parent_level >= NEW.level THEN
                        RAISE EXCEPTION 'Jerarquía inválida: el nivel del destino debe ser mayor que el de su padre' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.path NOT LIKE '%/' || NEW.parent_id::text || '/%' THEN
                        RAISE EXCEPTION 'Ruta de destino inconsistente con su padre' USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER catalog_destinations_level BEFORE INSERT OR UPDATE ON catalog_destinations FOR EACH ROW EXECUTE FUNCTION catalog_destinations_check_level()');

        Schema::create('catalog_providers', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('name', 160);
            $table->string('type', 20);
            $table->char('ruc', 11);
            $table->string('city', 80)->nullable();
            $table->smallInteger('stars')->nullable();
            $table->string('contact_name', 120);
            $table->string('phone', 40)->nullable();
            $table->string('email', 160)->nullable();
            $table->date('contract_start');
            $table->date('contract_end');
            $table->char('currency', 3);
            $table->string('payment_terms', 120)->nullable();
            $table->string('status', 20)->default('inactivo');
            $table->decimal('rating', 2, 1)->default(0);
            $table->text('rating_note')->nullable();
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'ruc']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'contract_end']);
        });
        DB::statement('CREATE INDEX catalog_providers_name_trgm ON catalog_providers USING gin (lower(name) gin_trgm_ops)');
        foreach ([
            "type_check CHECK (type IN ('Hotel','Transporte','Tren','Guía','Conductor','Restaurante','Operador','Actividad','Entrada','Otros'))",
            "ruc_check CHECK (ruc ~ '^(10|15|17|20)[0-9]{9}$')",
            'stars_check CHECK (stars BETWEEN 0 AND 5)',
            'contract_check CHECK (contract_end >= contract_start)',
            "currency_check CHECK (currency IN ('PEN','USD'))",
            "status_check CHECK (status IN ('activo','inactivo','vencido'))",
            'rating_check CHECK (rating = 0 OR rating BETWEEN 1 AND 5)',
        ] as $c) {
            DB::statement("ALTER TABLE catalog_providers ADD CONSTRAINT catalog_providers_{$c}");
        }

        Schema::create('catalog_provider_contacts', function (Blueprint $table) use ($base, $audit) {
            $base($table, false);
            $table->foreignUuid('provider_id')->index()->constrained('catalog_providers')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('role', 80)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 160)->nullable();
            $audit($table);
        });

        Schema::create('catalog_services', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->foreignUuid('provider_id')->constrained('catalog_providers');
            $table->string('name', 200);
            $table->string('type', 20);
            $table->string('unit', 20);
            $table->decimal('cost', 14, 2);
            $table->char('currency', 3);
            $table->integer('capacity')->nullable();
            $table->date('valid_from');
            $table->date('valid_to');
            $table->string('status', 20)->default('activo');
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->softDeletesTz();
            $table->index(['tenant_id', 'provider_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'valid_to']);
        });
        foreach ([
            "type_check CHECK (type IN ('Traslado','Transporte','Tren','Entrada','Guía','Alimentación','Alojamiento','Actividad','Tour','Seguro','Otro'))",
            "unit_check CHECK (unit IN ('pax','servicio','habitación','noche','vehículo','unidad'))",
            'cost_check CHECK (cost >= 0)',
            "currency_check CHECK (currency IN ('PEN','USD'))",
            'capacity_check CHECK (capacity IS NULL OR capacity >= 1)',
            'validity_check CHECK (valid_to >= valid_from)',
            "status_check CHECK (status IN ('activo','inactivo','vencido'))",
        ] as $c) {
            DB::statement("ALTER TABLE catalog_services ADD CONSTRAINT catalog_services_{$c}");
        }

        foreach (['catalog_product_types', 'catalog_product_categories', 'catalog_product_subcategories', 'catalog_destinations', 'catalog_providers', 'catalog_provider_contacts', 'catalog_services'] as $t) {
            Rls::tenantTable($t, ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
        }
    }

    public function down(): void
    {
        foreach (['catalog_services', 'catalog_provider_contacts', 'catalog_providers', 'catalog_destinations', 'catalog_product_subcategories', 'catalog_product_categories', 'catalog_product_types'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::statement('DROP FUNCTION IF EXISTS catalog_destinations_check_level()');
    }
};
