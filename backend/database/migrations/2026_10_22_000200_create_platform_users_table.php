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
        // Usuarios de plataforma (§3.1): guard `platform`, nunca tienen filas en iam_users.
        Schema::create('platform_users', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('name', 150);
            $table->string('email', 190)->unique();
            $table->string('password', 255);
            $table->text('mfa_secret');
            $table->bigInteger('mfa_last_used_step')->nullable();
            $table->string('status', 20)->default('activo');
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE platform_users ADD CONSTRAINT platform_users_status_check CHECK (status IN ('activo','inactivo'))");
        Rls::grantPlatform('platform_users', ['SELECT', 'INSERT', 'UPDATE']);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_users');
    }
};
