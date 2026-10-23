<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Spec 01 §3.2 y §3.3. destination_id / product_id apuntarán a Catálogo (spec 02) cuando exista.
    public function up(): void
    {
        Schema::create('crm_leads', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('code', 30);
            $table->string('full_name', 160);
            $table->string('company', 160)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('channel_code', 40);
            $table->string('source_code', 40);
            $table->string('interest_code', 40);
            $table->uuid('destination_id')->nullable();
            $table->string('destination_label', 120)->nullable();
            $table->uuid('product_id')->nullable();
            $table->date('travel_date')->nullable();
            $table->smallInteger('pax')->default(0);
            $table->string('status', 30)->default('nuevo');
            $table->foreignUuid('owner_id')->nullable()->constrained('iam_users');
            $table->smallInteger('score')->default(0);
            $table->text('notes')->nullable();
            $table->string('duplicate_of_type', 10)->nullable();
            $table->uuid('duplicate_of_id')->nullable();
            $table->timestampTz('validated_at');
            $table->timestampTz('assigned_at')->nullable();
            $table->foreignUuid('taken_by')->nullable()->constrained('iam_users');
            $table->timestampTz('first_contact_at')->nullable();
            $table->string('discard_reason_code', 40)->nullable();
            $table->text('discard_detail')->nullable();
            $table->timestampTz('discarded_at')->nullable();
            $table->smallInteger('discarded_at_step')->nullable();
            $table->foreignUuid('converted_client_id')->nullable()->constrained('crm_clients');
            $table->uuid('converted_opportunity_id')->nullable();
            $table->timestampTz('converted_at')->nullable();
            $table->foreignUuid('converted_by')->nullable()->constrained('iam_users');
            $table->integer('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'source_code']);
        });
        $emailNorm = 'lower(btrim(email))';
        $phoneNorm = "CASE WHEN length(regexp_replace(coalesce(phone, ''), '\\D', '', 'g')) >= 7 THEN right(regexp_replace(phone, '\\D', '', 'g'), 9) END";
        DB::statement("ALTER TABLE crm_leads ADD COLUMN email_norm varchar(190) GENERATED ALWAYS AS ({$emailNorm}) STORED");
        DB::statement("ALTER TABLE crm_leads ADD COLUMN phone_norm varchar(9) GENERATED ALWAYS AS ({$phoneNorm}) STORED");
        DB::statement('CREATE INDEX crm_leads_email_norm_idx ON crm_leads (tenant_id, email_norm)');
        DB::statement('CREATE INDEX crm_leads_phone_norm_idx ON crm_leads (tenant_id, phone_norm)');
        DB::statement("CREATE INDEX crm_leads_queue_idx ON crm_leads (tenant_id, created_at) WHERE owner_id IS NULL AND status IN ('nuevo','contactado')");
        foreach ([
            "crm_leads_status_check CHECK (status IN ('nuevo','contactado','calificado','convertido','duplicado','descartado'))",
            'crm_leads_contact_check CHECK (email IS NOT NULL OR phone IS NOT NULL)',
            "crm_leads_converted_check CHECK (status <> 'convertido' OR (converted_client_id IS NOT NULL AND converted_opportunity_id IS NOT NULL))",
            "crm_leads_discarded_check CHECK (status <> 'descartado' OR discard_reason_code IS NOT NULL)",
            'crm_leads_duplicate_of_check CHECK ((duplicate_of_type IS NULL) = (duplicate_of_id IS NULL))',
            "crm_leads_duplicate_type_check CHECK (duplicate_of_type IN ('lead','client'))",
            "crm_leads_owner_check CHECK (status IN ('nuevo','duplicado','descartado') OR owner_id IS NOT NULL)",
            'crm_leads_score_check CHECK (score BETWEEN 0 AND 100)',
            'crm_leads_pax_check CHECK (pax >= 0)',
        ] as $constraint) {
            DB::statement("ALTER TABLE crm_leads ADD CONSTRAINT {$constraint}");
        }
        Rls::tenantTable('crm_leads');

        Schema::table('crm_clients', fn (Blueprint $table) => $table->foreign('source_lead_id')->references('id')->on('crm_leads'));
        Schema::table('crm_client_contacts', fn (Blueprint $table) => $table->foreign('source_lead_id')->references('id')->on('crm_leads'));

        Schema::create('crm_lead_qualifications', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('lead_id')->index()->constrained('crm_leads');
            $table->smallInteger('budget');
            $table->smallInteger('authority');
            $table->smallInteger('need');
            $table->smallInteger('timeline');
            $table->decimal('declared_amount', 14, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('qualified_by')->constrained('iam_users');
            $table->timestampTz('qualified_at');
        });
        DB::statement('ALTER TABLE crm_lead_qualifications ADD COLUMN score smallint GENERATED ALWAYS AS (budget + authority + need + timeline) STORED');
        foreach (['budget', 'authority', 'need', 'timeline'] as $dimension) {
            DB::statement("ALTER TABLE crm_lead_qualifications ADD CONSTRAINT crm_lead_qualifications_{$dimension}_check CHECK ({$dimension} IN (0,10,18,25))");
        }
        DB::statement('ALTER TABLE crm_lead_qualifications ADD CONSTRAINT crm_lead_qualifications_amount_check CHECK (declared_amount >= 0)');
        DB::statement("ALTER TABLE crm_lead_qualifications ADD CONSTRAINT crm_lead_qualifications_currency_check CHECK (currency IN ('PEN','USD'))");
        Rls::tenantTable('crm_lead_qualifications', ['SELECT', 'INSERT']);
    }

    public function down(): void
    {
        Schema::table('crm_client_contacts', fn (Blueprint $table) => $table->dropForeign(['source_lead_id']));
        Schema::table('crm_clients', fn (Blueprint $table) => $table->dropForeign(['source_lead_id']));
        Schema::dropIfExists('crm_lead_qualifications');
        Schema::dropIfExists('crm_leads');
    }
};
