<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Spec 01 §3.7. La FK inversa quote_quotes.opportunity_id llega con Cotizaciones.
    public function up(): void
    {
        Schema::create('crm_opportunities', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('code', 30);
            $table->foreignUuid('client_id')->index()->constrained('crm_clients');
            $table->string('title', 200);
            $table->string('stage', 30);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->smallInteger('probability');
            $table->date('expected_close_date');
            $table->date('closed_at')->nullable();
            $table->foreignUuid('owner_id')->constrained('iam_users');
            $table->string('destination_label', 120)->nullable();
            $table->smallInteger('pax')->default(0);
            $table->foreignUuid('lead_id')->nullable()->constrained('crm_leads');
            $table->uuid('product_id')->nullable();
            $table->string('origin', 40)->nullable();
            $table->text('notes')->nullable();
            $table->string('lost_reason_code', 40)->nullable();
            $table->text('lost_detail')->nullable();
            $table->string('lost_at_stage', 30)->nullable();
            $table->integer('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'stage']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'expected_close_date']);
            $table->index(['tenant_id', 'created_at']);
        });
        DB::statement('CREATE UNIQUE INDEX crm_opportunities_lead_unique ON crm_opportunities (tenant_id, lead_id) WHERE lead_id IS NOT NULL');
        foreach ([
            "crm_opportunities_stage_check CHECK (stage IN ('calificacion','descubrimiento','cotizacion','negociacion','ganada','perdida'))",
            'crm_opportunities_amount_check CHECK (amount >= 0)',
            "crm_opportunities_currency_check CHECK (currency IN ('PEN','USD'))",
            'crm_opportunities_probability_check CHECK (probability BETWEEN 0 AND 100)',
            'crm_opportunities_pax_check CHECK (pax >= 0)',
            "crm_opportunities_lost_check CHECK (stage <> 'perdida' OR (lost_reason_code IS NOT NULL AND probability = 0 AND closed_at IS NOT NULL))",
            "crm_opportunities_won_check CHECK (stage <> 'ganada' OR (probability = 100 AND closed_at IS NOT NULL))",
            "crm_opportunities_open_check CHECK (stage IN ('ganada','perdida') OR closed_at IS NULL)",
        ] as $constraint) {
            DB::statement("ALTER TABLE crm_opportunities ADD CONSTRAINT {$constraint}");
        }
        Rls::tenantTable('crm_opportunities');

        Schema::table('crm_leads', fn (Blueprint $table) => $table->foreign('converted_opportunity_id')->references('id')->on('crm_opportunities'));
    }

    public function down(): void
    {
        Schema::table('crm_leads', fn (Blueprint $table) => $table->dropForeign(['converted_opportunity_id']));
        Schema::dropIfExists('crm_opportunities');
    }
};
