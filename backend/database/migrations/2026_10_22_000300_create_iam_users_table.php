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
        // §3.2. Los vínculos de usuarios externos (client_id, agency_id, …) apuntan a tablas de
        // módulos aún no creados; cada módulo agrega su FK al crear su tabla.
        Schema::create('iam_users', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('code', 20)->nullable();
            $table->string('name', 150);
            $table->string('email', 190);
            $table->string('password', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->foreignUuid('sede_id')->constrained('iam_sedes');
            $table->string('status', 20)->default('pendiente');
            $table->boolean('mfa_enabled')->default(false);
            $table->boolean('mfa_required')->default(false);
            $table->timestampTz('last_login_at')->nullable();
            $table->ipAddress('last_login_ip')->nullable();
            $table->char('avatar_color', 7)->nullable();
            $table->uuid('client_id')->nullable()->index();
            $table->uuid('agency_id')->nullable()->index();
            $table->uuid('provider_id')->nullable()->index();
            $table->uuid('guide_id')->nullable()->index();
            $table->uuid('driver_id')->nullable()->index();
            $table->uuid('reservation_id')->nullable()->index();
            $table->timestampTz('invited_at')->nullable();
            $table->uuid('invited_by')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->text('deactivated_reason')->nullable();
            $table->string('locale', 10)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'sede_id']);
        });
        Schema::table('iam_users', fn (Blueprint $table) => $table->foreign('invited_by')->references('id')->on('iam_users'));
        DB::statement('CREATE UNIQUE INDEX iam_users_tenant_email_unique ON iam_users (tenant_id, lower(email))');
        DB::statement("ALTER TABLE iam_users ADD CONSTRAINT iam_users_status_check CHECK (status IN ('pendiente','activo','inactivo'))");
        Rls::tenantTable('iam_users');

        Schema::create('iam_password_reset_tokens', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('email', 190);
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
            $table->primary(['tenant_id', 'email']);
        });
        Rls::tenantTable('iam_password_reset_tokens', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
    }

    public function down(): void
    {
        Schema::dropIfExists('iam_password_reset_tokens');
        Schema::dropIfExists('iam_users');
    }
};
