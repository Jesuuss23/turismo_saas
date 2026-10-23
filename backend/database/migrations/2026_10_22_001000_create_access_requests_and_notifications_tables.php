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
        // §3.10 Solicitudes desde la pantalla 403.
        Schema::create('iam_access_requests', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('user_id')->constrained('iam_users');
            $table->string('path', 250);
            $table->string('status', 20)->default('pendiente');
            $table->foreignUuid('resolved_by')->nullable()->constrained('iam_users');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('created_at');
            $table->index(['tenant_id', 'status']);
        });
        DB::statement('ALTER TABLE iam_access_requests ADD COLUMN required_permissions text[] NOT NULL DEFAULT \'{}\'');
        DB::statement("ALTER TABLE iam_access_requests ADD CONSTRAINT iam_access_requests_status_check CHECK (status IN ('pendiente','atendida','rechazada'))");
        Rls::tenantTable('iam_access_requests');

        // Mensajes del servicio Notify (§8.1). Reglas y plantillas (notif_event_rules, …) llegan con el módulo 09.
        Schema::create('notif_messages', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->bigIncrements('code');
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('user_id')->nullable()->constrained('iam_users');
            $table->string('event', 80);
            $table->string('channel', 20);
            $table->string('recipient', 190);
            $table->string('subject', 250);
            $table->text('body')->nullable();
            $table->jsonb('data')->nullable();
            $table->string('status', 20)->default('pendiente');
            $table->smallInteger('retries')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'user_id', 'created_at']);
        });
        DB::statement('ALTER TABLE notif_messages DROP CONSTRAINT notif_messages_pkey');
        DB::statement('ALTER TABLE notif_messages ADD PRIMARY KEY (id)');
        DB::statement("ALTER TABLE notif_messages ADD CONSTRAINT notif_messages_channel_check CHECK (channel IN ('email','whatsapp','push','portal'))");
        DB::statement("ALTER TABLE notif_messages ADD CONSTRAINT notif_messages_status_check CHECK (status IN ('pendiente','enviado','fallido'))");
        Rls::tenantTable('notif_messages');
        Rls::grantSequence('notif_messages_code_seq');
    }

    public function down(): void
    {
        Schema::dropIfExists('notif_messages');
        Schema::dropIfExists('iam_access_requests');
    }
};
