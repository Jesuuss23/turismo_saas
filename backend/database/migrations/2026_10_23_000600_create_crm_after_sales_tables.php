<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Spec 01 §3.9–§3.11. reservation_id apuntará a booking_reservations (Reservas) cuando exista.
    public function up(): void
    {
        Schema::create('crm_surveys', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('code', 30);
            $table->foreignUuid('client_id')->index()->constrained('crm_clients');
            $table->uuid('reservation_id')->nullable();
            $table->smallInteger('score');
            $table->text('comment')->nullable();
            $table->string('channel_code', 40);
            $table->timestampTz('responded_at');
            $table->foreignUuid('recorded_by')->constrained('iam_users');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'code']);
        });
        DB::statement("ALTER TABLE crm_surveys ADD COLUMN category varchar(10) GENERATED ALWAYS AS (CASE WHEN score >= 9 THEN 'promotor' WHEN score >= 7 THEN 'pasivo' ELSE 'detractor' END) STORED");
        DB::statement('ALTER TABLE crm_surveys ADD CONSTRAINT crm_surveys_score_check CHECK (score BETWEEN 0 AND 10)');
        DB::statement('CREATE UNIQUE INDEX crm_surveys_reservation_unique ON crm_surveys (tenant_id, reservation_id) WHERE reservation_id IS NOT NULL');
        Rls::tenantTable('crm_surveys', ['SELECT', 'INSERT']);

        Schema::create('crm_cases', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('code', 30);
            $table->foreignUuid('client_id')->constrained('crm_clients');
            $table->uuid('reservation_id')->nullable();
            $table->foreignUuid('survey_id')->nullable()->constrained('crm_surveys');
            $table->string('origin', 20);
            $table->string('type_code', 40);
            $table->string('priority', 10);
            $table->string('subject', 200);
            $table->text('description');
            $table->string('status', 20);
            $table->foreignUuid('owner_id')->constrained('iam_users');
            $table->text('resolution')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->integer('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'client_id']);
            $table->index(['tenant_id', 'created_at']);
        });
        DB::statement('CREATE UNIQUE INDEX crm_cases_survey_unique ON crm_cases (survey_id) WHERE survey_id IS NOT NULL');
        foreach ([
            "crm_cases_origin_check CHECK (origin IN ('manual','nps_auto'))",
            "crm_cases_priority_check CHECK (priority IN ('baja','media','alta','critica'))",
            "crm_cases_status_check CHECK (status IN ('abierta','en_proceso','resuelta','cerrada'))",
            "crm_cases_resolution_check CHECK (status <> 'resuelta' OR resolution IS NOT NULL)",
        ] as $constraint) {
            DB::statement("ALTER TABLE crm_cases ADD CONSTRAINT {$constraint}");
        }
        Rls::tenantTable('crm_cases');

        Schema::create('crm_case_events', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('case_id')->index()->constrained('crm_cases');
            $table->string('event', 20);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('message');
            $table->foreignUuid('user_id')->nullable()->constrained('iam_users');
            $table->timestampTz('created_at');
        });
        DB::statement("ALTER TABLE crm_case_events ADD CONSTRAINT crm_case_events_event_check CHECK (event IN ('registro','estado','seguimiento'))");
        Rls::tenantTable('crm_case_events', ['SELECT', 'INSERT']);
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_case_events');
        Schema::dropIfExists('crm_cases');
        Schema::dropIfExists('crm_surveys');
    }
};
