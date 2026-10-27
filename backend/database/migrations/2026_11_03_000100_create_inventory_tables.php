<?php

use App\Services\Inventory\InventoryProvisioner;
use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'catalog_allotments', 'catalog_allotment_requests', 'catalog_allotment_charges',
        'ops_travel_doc_items', 'ops_travel_doc_contacts', 'ops_travel_doc_special', 'ops_travel_doc_reminders',
    ];

    private const SECTIONS = "'general','currency','policies','operations','channels','notifications'";

    // Spec 11 §3: cupos contratados con proveedores (contrato, días, consumo, ampliaciones, penalidades, bitácora)
    // y documentos de viaje del pasajero (checklist, contacto de emergencia, atención especial, recordatorios).
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
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$name} CHECK ({$check})");
            }
        };

        // §3.1 contrato de cupo.
        Schema::create('catalog_allotments', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 30);
            $table->foreignUuid('provider_id')->constrained('catalog_providers');
            $table->foreignUuid('service_id')->constrained('catalog_services');
            $table->string('kind', 20);
            $table->string('name', 150);
            $table->string('contract_ref', 80)->nullable();
            $table->date('valid_from');
            $table->date('valid_to');
            $table->integer('base_qty');
            $table->smallInteger('release_days');
            $table->decimal('net_rate', 14, 2);
            $table->char('currency', 3);
            $table->decimal('penalty_pct', 5, 2)->default(0);
            $table->string('status', 20)->default('activo');
            $table->foreignUuid('owner_id')->constrained('iam_users');
            $table->text('notes')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->text('release_reason')->nullable();
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'code']);
            $table->index('provider_id');
            $table->index('service_id');
            $table->index(['tenant_id', 'status']);
        });
        $checks('catalog_allotments', [
            'kind_check' => "kind IN ('hotel','tren','entrada')", 'validity_check' => 'valid_to >= valid_from', 'qty_check' => 'base_qty > 0',
            'release_check' => 'release_days BETWEEN 0 AND 120', 'rate_check' => 'net_rate >= 0', 'currency_check' => "currency IN ('PEN','USD')",
            'penalty_check' => 'penalty_pct BETWEEN 0 AND 100', 'status_check' => "status IN ('activo','en_release','liberado','vencido')",
        ]);
        // CUP-BR-003: sin dos contratos vigentes solapados para el mismo servicio.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement("ALTER TABLE catalog_allotments ADD CONSTRAINT catalog_allotments_no_overlap EXCLUDE USING gist
            (tenant_id WITH =, service_id WITH =, daterange(valid_from, valid_to, '[]') WITH &&) WHERE (status IN ('activo','en_release') AND deleted_at IS NULL)");

        // §3.2 una fila por contrato × fecha. `available` se calcula (bloqueado − liberado − usado).
        Schema::create('catalog_allotment_days', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->foreignUuid('allotment_id')->constrained('catalog_allotments')->cascadeOnDelete();
            $table->date('service_date');
            $table->integer('blocked_qty');
            $table->integer('released_qty')->default(0);
            $table->integer('used_qty')->default(0);
            $table->integer('pending_qty')->default(0);
            $table->date('release_deadline');
            $table->string('retained_reason', 200)->nullable();
            $table->foreignUuid('retained_by')->nullable()->constrained('iam_users');
            $table->timestampTz('retained_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique(['allotment_id', 'service_date']);
            $table->index(['tenant_id', 'service_date']);
        });
        $checks('catalog_allotment_days', [
            'blocked_check' => 'blocked_qty >= 0', 'released_check' => 'released_qty BETWEEN 0 AND blocked_qty',
            'used_check' => 'used_qty >= 0', 'pending_check' => 'pending_qty >= 0',
        ]);
        DB::statement('CREATE VIEW catalog_allotment_days_v WITH (security_invoker = true) AS
            SELECT d.*, d.blocked_qty - d.released_qty - d.used_qty AS available_qty FROM catalog_allotment_days d');

        // §3.3 consumo derivado de booking_services (proyección idempotente).
        Schema::create('catalog_allotment_consumptions', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->foreignUuid('allotment_day_id')->constrained('catalog_allotment_days')->cascadeOnDelete();
            $table->foreignUuid('booking_service_id')->constrained('booking_services');
            $table->foreignUuid('reservation_id')->constrained('booking_reservations');
            $table->integer('qty');
            $table->string('state', 15);
            $audit($table);
            $table->unique(['allotment_day_id', 'booking_service_id']);
            $table->index('booking_service_id');
        });
        $checks('catalog_allotment_consumptions', ['qty_check' => 'qty > 0', 'state_check' => "state IN ('consumido','en_tramite','revertido')"]);

        // §3.4 ampliaciones.
        Schema::create('catalog_allotment_requests', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->foreignUuid('allotment_id')->constrained('catalog_allotments')->cascadeOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            $table->integer('extra_qty');
            $table->text('note');
            $table->string('status', 15)->default('solicitada');
            $table->text('response')->nullable();
            $table->timestampTz('responded_at')->nullable();
            $table->foreignUuid('responded_by')->nullable()->constrained('iam_users');
            $audit($table);
            $table->unique(['tenant_id', 'code']);
            $table->index('allotment_id');
        });
        $checks('catalog_allotment_requests', [
            'range_check' => 'date_to >= date_from', 'qty_check' => 'extra_qty > 0', 'status_check' => "status IN ('solicitada','aceptada','rechazada')",
        ]);

        // §3.5 penalidades por no-uso (un cargo por fecha).
        Schema::create('catalog_allotment_charges', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->foreignUuid('allotment_day_id')->unique()->constrained('catalog_allotment_days')->cascadeOnDelete();
            $table->integer('qty');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->decimal('fx_rate', 12, 6)->nullable();
            $table->string('reason', 200);
            $table->foreignUuid('payable_id')->nullable()->constrained('fin_payables');
            $audit($table);
        });
        $checks('catalog_allotment_charges', ['qty_check' => 'qty > 0', 'amount_check' => 'amount >= 0', 'currency_check' => "currency IN ('PEN','USD')"]);

        // §3.6 bitácora del contrato (solo inserción).
        Schema::create('catalog_allotment_events', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('allotment_id')->constrained('catalog_allotments')->cascadeOnDelete();
            $table->timestampTz('occurred_at');
            $table->foreignUuid('user_id')->nullable()->constrained('iam_users');
            $table->string('message', 300);
            $table->index(['allotment_id', 'occurred_at']);
        });

        // §3.7 checklist documental por pasajero. `vencido` y `no_aplica` se calculan al leer.
        Schema::create('ops_travel_doc_items', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->foreignUuid('reservation_id')->constrained('booking_reservations');
            $table->foreignUuid('passenger_id')->constrained('booking_passengers')->cascadeOnDelete();
            $table->string('doc_type', 20);
            $table->string('status', 15)->default('pendiente');
            $table->string('doc_number', 40)->nullable();
            $table->date('expiry_date')->nullable();
            $table->uuid('file_id')->nullable();
            $table->string('file_name', 200)->nullable();
            $table->string('file_mime', 100)->nullable();
            $table->integer('file_size')->nullable();
            $table->string('storage_path', 300)->nullable();
            $table->string('scan_status', 15)->nullable();
            $table->string('uploaded_by_type', 10)->nullable();
            $table->timestampTz('uploaded_at')->nullable();
            $table->text('observation')->nullable();
            $table->foreignUuid('validated_by')->nullable()->constrained('iam_users');
            $table->timestampTz('validated_at')->nullable();
            $table->string('note', 300)->nullable();
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique(['passenger_id', 'doc_type']);
            $table->index(['tenant_id', 'reservation_id']);
        });
        $checks('ops_travel_doc_items', [
            'type_check' => "doc_type IN ('id_doc','visa','insurance','yellow_fever','minor_auth','migration','emergency','special')",
            'status_check' => "status IN ('pendiente','recibido','completo','observado')",
            'uploader_check' => "uploaded_by_type IS NULL OR uploaded_by_type IN ('user','client','agency')",
            'scan_check' => "scan_status IS NULL OR scan_status IN ('pendiente','limpio','infectado')",
            'size_check' => 'file_size IS NULL OR file_size <= 10485760',
        ]);

        // §3.8 contacto de emergencia y atención especial (sin datos clínicos).
        Schema::create('ops_travel_doc_contacts', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->foreignUuid('passenger_id')->unique()->constrained('booking_passengers')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('phone', 30);
            $table->string('relation', 20);
            $audit($table);
        });
        $checks('ops_travel_doc_contacts', ['relation_check' => "relation IN ('Familiar','Pareja','Amigo(a)','Empresa','Otro')"]);
        Schema::create('ops_travel_doc_special', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->foreignUuid('passenger_id')->unique()->constrained('booking_passengers')->cascadeOnDelete();
            $table->boolean('requires_attention');
            $table->string('operational_note', 300)->nullable();
            $audit($table);
        });

        // §3.9 solicitudes y recordatorios enviados al cliente.
        Schema::create('ops_travel_doc_reminders', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->foreignUuid('reservation_id')->constrained('booking_reservations');
            $table->timestampTz('sent_at');
            $table->date('sent_on');
            $table->string('channels', 40);
            $table->string('recipient', 190);
            $table->string('trigger', 20);
            $table->smallInteger('days_before')->nullable();
            $table->smallInteger('items_count');
            $table->foreignUuid('sent_by')->nullable()->constrained('iam_users');
            $table->string('message', 500)->nullable();
            $audit($table);
            $table->index(['reservation_id', 'sent_at']);
        });
        $checks('ops_travel_doc_reminders', ['trigger_check' => "trigger IN ('auto','manual')"]);
        // Un recordatorio automático por reserva y día (idempotencia del job).
        DB::statement("CREATE UNIQUE INDEX ops_travel_doc_reminders_auto_unique ON ops_travel_doc_reminders (reservation_id, sent_on) WHERE trigger = 'auto'");

        foreach (self::TABLES as $table) {
            Rls::tenantTable($table);
        }
        // Acortar la vigencia borra los días sin consumo (y sus consumos revertidos).
        Rls::tenantTable('catalog_allotment_days', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
        Rls::tenantTable('catalog_allotment_consumptions', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
        Rls::tenantTable('catalog_allotment_events', ['SELECT', 'INSERT']);
        Rls::grantApp('catalog_allotment_days_v', ['SELECT']);

        // §3.10 reglas de documentos en sys_settings (sección `travel_docs`).
        DB::statement('ALTER TABLE sys_settings DROP CONSTRAINT IF EXISTS sys_settings_section_check');
        DB::statement('ALTER TABLE sys_settings ADD CONSTRAINT sys_settings_section_check CHECK (section IN ('.self::SECTIONS.",'travel_docs'))");

        foreach (DB::table('tenants')->get(['id']) as $tenant) {
            InventoryProvisioner::seedTenant($tenant->id, DB::connection());
        }
    }

    public function down(): void
    {
        DB::table('sys_scheduled_jobs')->whereIn('code', InventoryProvisioner::JOBS)->delete();
        DB::table('sys_sequences')->whereIn('doc_type', array_keys(InventoryProvisioner::SEQUENCES))->delete();
        DB::table('sys_settings')->where('section', 'travel_docs')->delete();
        DB::statement('ALTER TABLE sys_settings DROP CONSTRAINT IF EXISTS sys_settings_section_check');
        DB::statement('ALTER TABLE sys_settings ADD CONSTRAINT sys_settings_section_check CHECK (section IN ('.self::SECTIONS.'))');
        DB::statement('DROP VIEW IF EXISTS catalog_allotment_days_v');
        foreach (['ops_travel_doc_reminders', 'ops_travel_doc_special', 'ops_travel_doc_contacts', 'ops_travel_doc_items', 'catalog_allotment_events',
            'catalog_allotment_charges', 'catalog_allotment_requests', 'catalog_allotment_consumptions', 'catalog_allotment_days', 'catalog_allotments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
