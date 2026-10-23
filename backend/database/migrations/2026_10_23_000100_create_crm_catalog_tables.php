<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Spec 01 §3.1 y §3.6: catálogos configurables y etapas del pipeline, por tenant.
    public function up(): void
    {
        Schema::create('crm_catalog_items', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('kind', 30);
            $table->string('code', 40);
            $table->string('label', 120);
            $table->boolean('requires_detail')->default(false);
            $table->smallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'kind', 'code']);
        });
        DB::statement("ALTER TABLE crm_catalog_items ADD CONSTRAINT crm_catalog_items_kind_check CHECK (kind IN ('lead_source','lead_channel','lead_interest','client_segment','lost_reason','discard_reason','case_type','survey_channel','activity_type'))");
        Rls::tenantTable('crm_catalog_items');

        Schema::create('crm_pipeline_stages', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('code', 30);
            $table->string('name', 60);
            $table->smallInteger('default_probability');
            $table->smallInteger('sort_order');
            $table->boolean('is_closed')->default(false);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'code']);
        });
        DB::statement("ALTER TABLE crm_pipeline_stages ADD CONSTRAINT crm_pipeline_stages_code_check CHECK (code IN ('calificacion','descubrimiento','cotizacion','negociacion','ganada','perdida'))");
        DB::statement('ALTER TABLE crm_pipeline_stages ADD CONSTRAINT crm_pipeline_stages_probability_check CHECK (default_probability BETWEEN 0 AND 100)');
        Rls::tenantTable('crm_pipeline_stages');
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_pipeline_stages');
        Schema::dropIfExists('crm_catalog_items');
    }
};
