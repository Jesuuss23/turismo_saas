<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // §3.7 Correlativos por tenant, tipo de documento y serie.
    public function up(): void
    {
        Schema::create('sys_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('doc_type', 40);
            $table->string('label', 60);
            $table->string('series', 10)->default('default');
            $table->string('prefix', 30);
            $table->smallInteger('pad_width');
            $table->bigInteger('next_number');
            $table->boolean('year_reset')->default(true);
            $table->timestampTz('updated_at');
            $table->unique(['tenant_id', 'doc_type', 'series']);
        });
        DB::statement('ALTER TABLE sys_sequences ADD CONSTRAINT sys_sequences_next_positive CHECK (next_number > 0)');

        // Nunca retrocede; solo se reinicia junto con un cambio de prefijo (reinicio anual).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION sys_sequences_no_decrease() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.next_number < OLD.next_number AND NEW.prefix = OLD.prefix THEN
                    RAISE EXCEPTION 'El correlativo % (%) no puede retroceder de % a %',
                        OLD.doc_type, OLD.series, OLD.next_number, NEW.next_number;
                END IF;
                IF NEW.next_number < OLD.next_number AND NOT OLD.year_reset THEN
                    RAISE EXCEPTION 'El correlativo % (%) no admite reinicio', OLD.doc_type, OLD.series;
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER sys_sequences_no_decrease BEFORE UPDATE ON sys_sequences FOR EACH ROW EXECUTE FUNCTION sys_sequences_no_decrease()');

        Rls::tenantTable('sys_sequences', ['SELECT', 'UPDATE']);
    }

    public function down(): void
    {
        Schema::dropIfExists('sys_sequences');
        DB::statement('DROP FUNCTION IF EXISTS sys_sequences_no_decrease()');
    }
};
