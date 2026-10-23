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
        // §3.8
        Schema::create('sys_idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants');
            $table->string('key', 80);
            $table->uuid('user_id')->nullable();
            $table->string('method', 10);
            $table->string('path', 250);
            $table->char('request_hash', 64);
            $table->string('status', 20);
            $table->smallInteger('response_code')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('locked_until')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('expires_at')->index();
        });
        DB::statement('ALTER TABLE sys_idempotency_keys ADD CONSTRAINT sys_idempotency_keys_tenant_key_unique UNIQUE NULLS NOT DISTINCT (tenant_id, key)');
        DB::statement("ALTER TABLE sys_idempotency_keys ADD CONSTRAINT sys_idempotency_keys_status_check CHECK (status IN ('processing','completed','failed'))");
        Rls::nullableTenantTable('sys_idempotency_keys', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);

        // §3.9
        Schema::create('sys_outbox', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants');
            $table->string('aggregate_type', 40);
            $table->uuid('aggregate_id');
            $table->string('event', 80);
            $table->jsonb('payload');
            $table->string('status', 20)->default('pending');
            $table->smallInteger('attempts')->default(0);
            $table->timestampTz('available_at');
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('created_at');
            $table->index(['status', 'available_at']);
        });
        DB::statement("ALTER TABLE sys_outbox ADD CONSTRAINT sys_outbox_status_check CHECK (status IN ('pending','dispatched','failed'))");
        Rls::nullableTenantTable('sys_outbox', ['SELECT', 'INSERT', 'UPDATE']);
    }

    public function down(): void
    {
        Schema::dropIfExists('sys_outbox');
        Schema::dropIfExists('sys_idempotency_keys');
    }
};
