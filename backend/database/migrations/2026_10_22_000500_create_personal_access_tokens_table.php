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
        // Sanctum extendida (§3.4). Sin RLS: es la fuente del tenant de la solicitud y se
        // consulta por hash antes de conocerlo. La revocación masiva usa tenant_id/tokenable.
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants');
            $table->string('experience', 20);
            $table->timestampTz('mfa_verified_at')->nullable();
            $table->string('device_name', 120)->nullable();
            $table->ipAddress('ip')->nullable();
            $table->timestampsTz();
            $table->index('tenant_id');
        });
        DB::statement("ALTER TABLE personal_access_tokens ADD CONSTRAINT pat_experience_check CHECK (experience IN ('backoffice','portal','mobile','platform'))");
        DB::statement("ALTER TABLE personal_access_tokens ADD CONSTRAINT pat_tenant_check CHECK ((experience = 'platform') = (tenant_id IS NULL))");
        Rls::grantApp('personal_access_tokens', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
        Rls::grantSequence('personal_access_tokens_id_seq');
        Rls::grantPlatform('personal_access_tokens', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
        Rls::grantSequence('personal_access_tokens_id_seq', 'platform');
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
