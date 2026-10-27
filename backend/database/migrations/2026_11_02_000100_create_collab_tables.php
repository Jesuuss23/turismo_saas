<?php

use App\Services\Collab\CollabProvisioner;
use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'collab_approval_rules', 'collab_approval_requests', 'collab_approval_steps', 'collab_approval_info_requests',
        'collab_channel_accounts', 'collab_reply_templates', 'collab_conversations', 'collab_messages', 'collab_message_attachments',
        'channel_b2b_credit_exceptions',
    ];

    // Spec 10 §3: Colaboración. Motor de aprobaciones (reglas, solicitudes polimórficas, niveles, consultas y trazabilidad)
    // y bandeja omnicanal (cuentas de canal, eventos entrantes, conversaciones, mensajes, adjuntos y plantillas).
    public function up(): void
    {
        $base = function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->constrained('tenants');
        };
        $audit = function (Blueprint $table) {
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
        };
        $checks = function (string $table, array $checks) {
            foreach ($checks as $name => $check) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_{$name}");
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$name} CHECK ({$check})");
            }
        };
        $types = "'quote','cancel','reversal','payable','commission','provider_rate','credit_b2b','refund','other'";

        // §3.1 matriz de enrutamiento.
        Schema::create('collab_approval_rules', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 30);
            $table->string('request_type', 30);
            $table->smallInteger('level');
            $table->string('permission', 80);
            $table->decimal('min_amount_pen', 14, 2)->default(0);
            $table->smallInteger('sla_hours');
            $table->boolean('forbid_self_approval')->default(true);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_origin_locked')->default(false);
            $table->integer('lock_version')->default(1);
            $audit($table);
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'request_type', 'is_active']);
        });
        DB::statement('CREATE UNIQUE INDEX collab_approval_rules_threshold_unique ON collab_approval_rules (tenant_id, request_type, level, min_amount_pen) WHERE deleted_at IS NULL');
        $checks('collab_approval_rules', [
            'type_check' => "request_type IN ({$types})", 'level_check' => 'level IN (1,2)',
            'sla_check' => 'sla_hours BETWEEN 1 AND 720', 'amount_check' => 'min_amount_pen >= 0',
        ]);

        // §3.2 solicitud unificada. `current_rule_id` y las marcas de SLA son de implementación (alertas una sola vez).
        Schema::create('collab_approval_requests', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 30);
            $table->string('request_type', 30);
            $table->boolean('is_generic')->default(false);
            $table->string('subject_type', 40)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('subject_code', 40)->nullable();
            $table->integer('subject_version')->nullable();
            $table->string('dedupe_key', 120);
            $table->smallInteger('round')->default(1);
            $table->string('title', 200);
            $table->text('justification')->nullable();
            $table->foreignUuid('requester_id')->nullable()->constrained('iam_users');
            $table->string('requester_kind', 20)->default('user');
            $table->foreignUuid('requester_supplier_id')->nullable()->constrained('catalog_providers');
            $table->decimal('amount', 14, 2)->default(0);
            $table->char('currency', 3)->default('PEN');
            $table->decimal('fx_rate', 12, 6)->default(1);
            $table->string('status', 30)->default('pendiente');
            $table->smallInteger('current_level')->default(1);
            $table->smallInteger('total_levels')->default(1);
            $table->foreignUuid('current_rule_id')->nullable()->constrained('collab_approval_rules');
            $table->foreignUuid('delegated_to')->nullable()->constrained('iam_users');
            $table->timestampTz('level_started_at')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('paused_at')->nullable();
            $table->timestampTz('sla_warned_at')->nullable();
            $table->timestampTz('sla_overdue_at')->nullable();
            $table->foreignUuid('decided_by')->nullable()->constrained('iam_users');
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->boolean('closed_in_origin')->default(false);
            $table->jsonb('origin_snapshot')->nullable();
            $table->integer('lock_version')->default(1);
            $audit($table);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status', 'due_at']);
            $table->index(['tenant_id', 'request_type', 'status']);
            $table->index(['tenant_id', 'subject_type', 'subject_id']);
        });
        DB::statement('ALTER TABLE collab_approval_requests ADD COLUMN amount_base numeric(14,2) GENERATED ALWAYS AS (round(amount * fx_rate, 2)) STORED');
        DB::statement('CREATE INDEX collab_approval_requests_requester_index ON collab_approval_requests (tenant_id, requester_id, created_at DESC)');
        DB::statement('CREATE INDEX collab_approval_requests_delegated_index ON collab_approval_requests (tenant_id, delegated_to) WHERE delegated_to IS NOT NULL');
        DB::statement("CREATE UNIQUE INDEX collab_approval_requests_live_unique ON collab_approval_requests (tenant_id, dedupe_key) WHERE status IN ('pendiente','info')");
        $checks('collab_approval_requests', [
            'type_check' => "request_type IN ({$types})",
            'subject_type_check' => "subject_type IS NULL OR subject_type IN ('quote_versions','booking_reservations','fin_payments','fin_payables','fin_commissions','supplier_rate_requests','channel_b2b_agencies')",
            'requester_kind_check' => "requester_kind IN ('user','supplier','system')",
            'currency_check' => "currency IN ('PEN','USD')",
            'status_check' => "status IN ('pendiente','info','aprobada','rechazada','anulada')",
            'level_check' => 'current_level BETWEEN 1 AND 2 AND total_levels BETWEEN 1 AND 2 AND current_level <= total_levels',
            'decision_note_check' => "status <> 'rechazada' OR decision_note IS NOT NULL",
            'amount_check' => 'amount >= 0 AND fx_rate > 0',
        ]);

        // §3.3
        Schema::create('collab_approval_steps', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('request_id')->index()->constrained('collab_approval_requests')->restrictOnDelete();
            $table->smallInteger('level');
            $table->foreignUuid('rule_id')->constrained('collab_approval_rules');
            $table->string('decision', 20);
            $table->foreignUuid('decided_by')->constrained('iam_users');
            $table->text('note')->nullable();
            $table->timestampTz('decided_at');
            $table->timestampsTz();
            $table->unique(['request_id', 'level']);
            $table->unique(['request_id', 'decided_by']);
        });
        $checks('collab_approval_steps', ['decision_check' => "decision IN ('aprobada','rechazada')"]);

        // §3.4
        Schema::create('collab_approval_info_requests', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('request_id')->index()->constrained('collab_approval_requests');
            $table->foreignUuid('asked_by')->constrained('iam_users');
            $table->text('question');
            $table->timestampTz('asked_at');
            $table->text('answer')->nullable();
            $table->foreignUuid('answered_by')->nullable()->constrained('iam_users');
            $table->timestampTz('answered_at')->nullable();
            $table->timestampsTz();
        });

        // §3.5 trazabilidad, solo inserción.
        Schema::create('collab_approval_events', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('request_id')->index()->constrained('collab_approval_requests');
            $table->string('event', 40);
            $table->foreignUuid('actor_id')->nullable()->constrained('iam_users');
            $table->text('note')->nullable();
            $table->jsonb('payload')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestampTz('occurred_at');
        });
        $checks('collab_approval_events', ['event_check' => "event IN ('creada','aprobada_nivel','aprobada','rechazada','info_solicitada','info_respondida','delegada','retirada','reenviada','cerrada_en_origen','sla_advertencia','sla_vencido')"]);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION collab_events_insert_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'collab_approval_events es solo inserción';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER collab_approval_events_insert_only BEFORE UPDATE OR DELETE ON collab_approval_events
                FOR EACH ROW EXECUTE FUNCTION collab_events_insert_only();
        SQL);

        // §3.10 cuentas de canal (credenciales cifradas por cast) y eventos entrantes.
        Schema::create('collab_channel_accounts', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('channel', 20);
            $table->string('name', 120);
            $table->text('credentials_encrypted')->nullable();
            $table->text('webhook_secret_encrypted')->nullable();
            $table->string('phone_number_id', 60)->nullable();
            $table->string('mailbox', 190)->nullable();
            $table->smallInteger('first_response_sla_minutes');
            $table->string('status', 15)->default('sandbox');
            $audit($table);
            $table->unique(['tenant_id', 'channel']);
        });
        $checks('collab_channel_accounts', [
            'channel_check' => "channel IN ('whatsapp','email','webchat','form','portal')",
            'status_check' => "status IN ('activo','sandbox','inactivo')",
            'sla_check' => 'first_response_sla_minutes BETWEEN 1 AND 10080',
        ]);
        DB::statement('CREATE UNIQUE INDEX collab_channel_accounts_phone_unique ON collab_channel_accounts (phone_number_id) WHERE phone_number_id IS NOT NULL');

        Schema::create('collab_inbound_events', function (Blueprint $table) use ($base) {
            $base($table);
            $table->string('channel', 20);
            $table->string('external_event_id', 190);
            $table->jsonb('payload');
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->string('status', 15)->default('recibido');
            $table->text('error')->nullable();
            $table->unique(['channel', 'external_event_id']);
            $table->index(['tenant_id', 'received_at']);
        });
        $checks('collab_inbound_events', ['status_check' => "status IN ('recibido','procesado','duplicado','error')"]);

        // §3.9
        Schema::create('collab_reply_templates', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 30);
            $table->string('name', 120);
            $table->text('body');
            $table->jsonb('channels')->default('[]');
            $table->boolean('is_whatsapp_approved')->default(false);
            $table->string('whatsapp_template_name', 120)->nullable();
            $table->char('whatsapp_language', 5)->nullable();
            $table->boolean('is_active')->default(true);
            $audit($table);
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'code']);
        });

        // §3.6
        Schema::create('collab_conversations', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 30);
            $table->string('channel', 20);
            $table->foreignUuid('channel_account_id')->nullable()->constrained('collab_channel_accounts');
            $table->string('external_thread_id', 190)->nullable();
            $table->string('contact_name', 160);
            $table->string('contact_email', 190)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('subject', 200);
            $table->string('status', 20)->default('abierta');
            $table->string('priority', 10)->default('media');
            $table->jsonb('tags')->default('[]');
            $table->foreignUuid('assignee_id')->nullable()->constrained('iam_users');
            $table->foreignUuid('client_id')->nullable()->constrained('crm_clients');
            $table->foreignUuid('lead_id')->nullable()->constrained('crm_leads');
            $table->foreignUuid('reservation_id')->nullable()->constrained('booking_reservations');
            $table->foreignUuid('quote_id')->nullable()->constrained('quote_quotes');
            $table->foreignUuid('support_ticket_id')->nullable()->unique()->constrained('channel_support_tickets');
            $table->timestampTz('first_response_due_at');
            $table->timestampTz('first_response_at')->nullable();
            $table->timestampTz('sla_alerted_at')->nullable();
            $table->timestampTz('last_inbound_at')->nullable()->index();
            $table->timestampTz('last_message_at');
            $table->integer('unread_count')->default(0);
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('iam_users');
            $table->integer('lock_version')->default(1);
            $audit($table);
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'channel', 'external_thread_id']);
            $table->index(['tenant_id', 'assignee_id', 'status']);
            $table->index(['tenant_id', 'contact_phone']);
        });
        DB::statement('CREATE INDEX collab_conversations_email_index ON collab_conversations (tenant_id, lower(contact_email))');
        DB::statement('CREATE INDEX collab_conversations_list_index ON collab_conversations (tenant_id, status, last_message_at DESC)');
        DB::statement('CREATE INDEX collab_conversations_tags_index ON collab_conversations USING GIN (tags)');
        $checks('collab_conversations', [
            'channel_check' => "channel IN ('whatsapp','email','webchat','form','portal')",
            'status_check' => "status IN ('abierta','pendiente','resuelta')",
            'priority_check' => "priority IN ('baja','media','alta','critica')",
        ]);

        // §3.7
        Schema::create('collab_messages', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('conversation_id')->constrained('collab_conversations');
            $table->string('channel', 20);
            $table->string('direction', 10);
            $table->foreignUuid('author_user_id')->nullable()->constrained('iam_users');
            $table->text('body');
            $table->foreignUuid('template_id')->nullable()->constrained('collab_reply_templates');
            $table->string('external_message_id', 190)->nullable();
            $table->string('delivery_status', 20)->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestampTz('sent_at');
            $table->timestampsTz();
            $table->index(['conversation_id', 'sent_at']);
            $table->unique(['tenant_id', 'channel', 'external_message_id']);
        });
        $checks('collab_messages', [
            'direction_check' => "direction IN ('in','out','internal','system')",
            'delivery_check' => "delivery_status IS NULL OR delivery_status IN ('encolado','enviado','entregado','leido','fallido')",
        ]);

        // §3.8
        Schema::create('collab_message_attachments', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('message_id')->nullable()->index()->constrained('collab_messages');
            $table->string('file_name', 200);
            $table->string('mime', 100);
            $table->integer('size_bytes');
            $table->string('storage_path', 300);
            $table->char('sha256', 64)->nullable();
            $table->string('scan_status', 15)->default('pendiente');
            $table->uuid('uploaded_by')->nullable();
            $table->timestampsTz();
        });
        $checks('collab_message_attachments', [
            'size_check' => 'size_bytes > 0 AND size_bytes <= 16777216',
            'scan_check' => "scan_status IN ('pendiente','limpio','infectado')",
        ]);

        // §3.11 campos en tablas de otros módulos.
        $checks('booking_cancel_requests', ['status_check' => "status IN ('pendiente','aprobada','rechazada','retirada')"]);
        Schema::table('fin_payables', function (Blueprint $table) {
            $table->smallInteger('approval_round')->default(1);
            $table->text('observation')->nullable();
        });
        Schema::table('fin_commissions', function (Blueprint $table) {
            $table->smallInteger('approval_round')->default(1);
            $table->text('observation')->nullable();
        });
        Schema::table('fin_payments', function (Blueprint $table) {
            $table->foreignUuid('reversal_request_id')->nullable()->constrained('collab_approval_requests');
        });
        Schema::create('channel_b2b_credit_exceptions', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('agency_id')->index()->constrained('channel_b2b_agencies');
            $table->foreignUuid('approval_request_id')->constrained('collab_approval_requests');
            $table->decimal('amount', 14, 2);
            $table->decimal('limit_before', 14, 2);
            $table->decimal('limit_after', 14, 2);
            $table->date('valid_until')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at');
        });

        foreach (self::TABLES as $table) {
            Rls::tenantTable($table);
        }
        Rls::tenantTable('collab_approval_events', ['SELECT', 'INSERT']); // §3.5: solo inserción (además del trigger).
        // Los webhooks resuelven el tenant por la cuenta del canal; el evento entrante se registra con su tenant.
        Rls::tenantTable('collab_inbound_events');
        foreach (DB::table('tenants')->get(['id']) as $tenant) {
            CollabProvisioner::seedTenant($tenant->id, DB::connection());
        }
    }

    public function down(): void
    {
        Schema::table('fin_payments', fn (Blueprint $t) => $t->dropConstrainedForeignId('reversal_request_id'));
        Schema::table('fin_payables', fn (Blueprint $t) => $t->dropColumn(['approval_round', 'observation']));
        Schema::table('fin_commissions', fn (Blueprint $t) => $t->dropColumn(['approval_round', 'observation']));
        DB::statement("DELETE FROM booking_cancel_requests WHERE status = 'retirada'");
        DB::statement('ALTER TABLE booking_cancel_requests DROP CONSTRAINT IF EXISTS booking_cancel_requests_status_check');
        DB::statement("ALTER TABLE booking_cancel_requests ADD CONSTRAINT booking_cancel_requests_status_check CHECK (status IN ('pendiente','aprobada','rechazada'))");
        foreach (['channel_b2b_credit_exceptions', 'collab_message_attachments', 'collab_messages', 'collab_conversations', 'collab_reply_templates',
            'collab_inbound_events', 'collab_channel_accounts', 'collab_approval_events', 'collab_approval_info_requests', 'collab_approval_steps',
            'collab_approval_requests', 'collab_approval_rules'] as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table} CASCADE");
        }
        DB::statement('DROP FUNCTION IF EXISTS collab_events_insert_only()');
    }
};
