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
        Schema::create('iam_mfa_factors', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('user_id')->unique()->constrained('iam_users');
            $table->string('type', 10)->default('totp');
            $table->text('secret');
            $table->timestampTz('confirmed_at')->nullable();
            $table->jsonb('recovery_codes')->default('[]');
            $table->bigInteger('last_used_step')->nullable();
            $table->timestampTz('created_at');
        });
        DB::statement("ALTER TABLE iam_mfa_factors ADD CONSTRAINT iam_mfa_factors_type_check CHECK (type IN ('totp'))");
        Rls::tenantTable('iam_mfa_factors', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
    }

    public function down(): void
    {
        Schema::dropIfExists('iam_mfa_factors');
    }
};
