<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabla de plataforma: no lleva RLS; la app solo puede leer (resolver tenant y su estado).
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('slug', 60)->unique();
            $table->string('legal_name', 200);
            $table->string('trade_name', 150)->nullable();
            $table->string('ruc', 11)->nullable();
            $table->string('plan', 40)->default('estandar');
            $table->char('currency', 3)->default('PEN');
            $table->decimal('fx_rate', 10, 4)->default(1);
            $table->string('timezone', 60)->default('America/Lima');
            $table->decimal('igv_pct', 5, 2)->default(18);
            $table->string('status', 20)->default('activo');
            $table->jsonb('settings')->default('{}');
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_status_check CHECK (status IN ('activo','suspendido'))");
        Rls::grantApp('tenants', ['SELECT']);
        Rls::grantPlatform('tenants', ['SELECT', 'INSERT', 'UPDATE']);

        Schema::create('iam_sedes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('code', 20);
            $table->string('name', 150);
            $table->string('city', 100)->nullable();
            $table->boolean('is_main')->default(false);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'code']);
        });
        Rls::tenantTable('iam_sedes');
    }

    public function down(): void
    {
        Schema::dropIfExists('iam_sedes');
        Schema::dropIfExists('tenants');
    }
};
