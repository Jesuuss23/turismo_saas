<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Spec 01 §3.8.
    public function up(): void
    {
        Schema::create('crm_activities', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('code', 30);
            $table->string('type', 20);
            $table->string('subject', 200);
            $table->text('description')->nullable();
            $table->string('related_type', 20)->nullable();
            $table->uuid('related_id')->nullable();
            $table->foreignUuid('owner_id')->constrained('iam_users');
            $table->timestampTz('due_at');
            $table->string('status', 20)->default('pendiente');
            $table->text('result')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignUuid('completed_by')->nullable()->constrained('iam_users');
            $table->boolean('auto_closed')->default(false);
            $table->uuid('previous_activity_id')->nullable();
            $table->foreignUuid('merged_from_lead_id')->nullable()->constrained('crm_leads');
            $table->integer('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'owner_id', 'status', 'due_at']);
            $table->index(['tenant_id', 'related_type', 'related_id']);
        });
        Schema::table('crm_activities', fn (Blueprint $table) => $table->foreign('previous_activity_id')->references('id')->on('crm_activities'));
        DB::statement("CREATE INDEX crm_activities_open_due_idx ON crm_activities (tenant_id, due_at) WHERE status <> 'completada'");
        foreach ([
            "crm_activities_type_check CHECK (type IN ('llamada','email','whatsapp','reunion','tarea','postventa'))",
            "crm_activities_related_type_check CHECK (related_type IN ('lead','opportunity','client','quote','reservation'))",
            'crm_activities_related_check CHECK ((related_type IS NULL) = (related_id IS NULL))',
            "crm_activities_status_check CHECK (status IN ('pendiente','vencida','completada'))",
            "crm_activities_result_check CHECK (status <> 'completada' OR result IS NOT NULL)",
        ] as $constraint) {
            DB::statement("ALTER TABLE crm_activities ADD CONSTRAINT {$constraint}");
        }
        Rls::tenantTable('crm_activities');
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_activities');
    }
};
