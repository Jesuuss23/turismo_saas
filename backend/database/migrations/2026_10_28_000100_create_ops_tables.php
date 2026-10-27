<?php

use App\Services\Ops\OpsProvisioner;
use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'ops_guides', 'ops_drivers', 'ops_vehicles', 'ops_orders', 'ops_order_tasks', 'ops_supplier_requests', 'ops_assignments',
        'ops_assignment_checkins', 'ops_rooming_lists', 'ops_rooming_rooms', 'ops_rooming_guests', 'ops_vouchers', 'ops_voucher_versions',
        'ops_incidents', 'ops_incident_logs', 'ops_evidences', 'ops_mobile_devices', 'ops_sync_actions', 'channel_trip_messages',
    ];

    // Spec 05 §3: orden operativa, recursos, asignaciones, rooming, vouchers, incidencias, evidencias y sincronización móvil.
    // Las alertas del pasajero son notif_messages (read_at ya cubre notif_reads, §3.14).
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

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

        // §3.3: columnas operativas del servicio de la reserva (reemplazan la heurística por nombre, §11-24).
        Schema::table('booking_services', function (Blueprint $table) {
            $table->boolean('requires_assignment')->default(false);
            $table->string('service_kind', 20)->default('libre');
            $table->foreignUuid('requested_by')->nullable()->constrained('iam_users');
            $table->foreignUuid('confirmed_by')->nullable()->constrained('iam_users');
        });
        DB::statement('ALTER TABLE booking_services DROP CONSTRAINT booking_services_status_check');
        $checks('booking_services', [
            'status_check' => "status IN ('pendiente','solicitado','confirmado','rechazado','en_curso','cancelado','completado')",
            'kind_check' => "service_kind IN ('tour','traslado','alojamiento','tren_entrada','libre')",
        ]);
        DB::statement(<<<'SQL'
            UPDATE booking_services b SET
                service_kind = CASE s.type
                    WHEN 'Traslado' THEN 'traslado' WHEN 'Transporte' THEN 'traslado'
                    WHEN 'Tour' THEN 'tour' WHEN 'Actividad' THEN 'tour' WHEN 'Guía' THEN 'tour'
                    WHEN 'Alojamiento' THEN 'alojamiento' WHEN 'Tren' THEN 'tren_entrada' WHEN 'Entrada' THEN 'tren_entrada'
                    ELSE 'libre' END,
                requires_assignment = s.type IN ('Traslado','Transporte','Tour','Actividad','Guía')
            FROM catalog_services s WHERE s.id = b.catalog_service_id
            SQL);

        // §3.7 Recursos.
        Schema::create('ops_guides', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->foreignUuid('user_id')->nullable()->constrained('iam_users');
            $table->foreignUuid('provider_id')->nullable()->constrained('catalog_providers');
            $table->string('full_name', 150);
            $table->jsonb('languages');
            $table->string('license_number', 40);
            $table->date('license_expires_on')->nullable();
            $table->decimal('rating', 2, 1)->nullable();
            $table->string('status', 20)->default('disponible');
            $table->string('phone', 30)->nullable();
            $audit($table);
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'code']);
        });
        DB::statement('CREATE UNIQUE INDEX ops_guides_user_unique ON ops_guides (tenant_id, user_id) WHERE user_id IS NOT NULL');
        DB::statement('CREATE INDEX ops_guides_languages_gin ON ops_guides USING gin (languages)');
        $checks('ops_guides', ['status_check' => "status IN ('disponible','asignado','descanso','vacaciones')", 'rating_check' => 'rating IS NULL OR rating BETWEEN 0 AND 5']);

        Schema::create('ops_drivers', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->foreignUuid('user_id')->nullable()->constrained('iam_users');
            $table->foreignUuid('provider_id')->nullable()->constrained('catalog_providers');
            $table->string('full_name', 150);
            $table->string('license_number', 40);
            $table->string('license_class', 10);
            $table->date('license_expires_on');
            $table->string('status', 20)->default('disponible');
            $table->string('phone', 30)->nullable();
            $audit($table);
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'license_expires_on']);
        });
        DB::statement('CREATE UNIQUE INDEX ops_drivers_user_unique ON ops_drivers (tenant_id, user_id) WHERE user_id IS NOT NULL');
        $checks('ops_drivers', ['status_check' => "status IN ('disponible','asignado','descanso','vacaciones')"]);

        Schema::create('ops_vehicles', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->string('plate', 12);
            $table->string('vehicle_type', 60);
            $table->smallInteger('capacity');
            $table->foreignUuid('provider_id')->nullable()->constrained('catalog_providers');
            $table->date('soat_expires_on');
            $table->string('status', 20)->default('disponible');
            $audit($table);
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'plate']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'soat_expires_on']);
        });
        $checks('ops_vehicles', ['status_check' => "status IN ('disponible','asignado','mantenimiento')", 'capacity_check' => 'capacity > 0']);

        // §3.1 / §3.2 Orden operativa y checklist.
        Schema::create('ops_orders', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 30);
            $table->foreignUuid('reservation_id')->constrained('booking_reservations');
            $table->string('title', 200);
            $table->string('status', 30);
            $table->foreignUuid('coordinator_id')->constrained('iam_users');
            $table->date('date_from');
            $table->date('date_to');
            $table->timestampTz('closed_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('iam_users');
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'coordinator_id']);
            $table->index(['tenant_id', 'date_from']);
        });
        DB::statement('CREATE UNIQUE INDEX ops_orders_reservation_unique ON ops_orders (tenant_id, reservation_id) WHERE deleted_at IS NULL');
        $checks('ops_orders', [
            'status_check' => "status IN ('planificada','pendiente_asignacion','asignada','confirmada','en_ejecucion','completada','cancelada')",
            'dates_check' => 'date_to >= date_from',
        ]);

        Schema::create('ops_order_tasks', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->foreignUuid('order_id')->index()->constrained('ops_orders');
            $table->string('title', 250);
            $table->smallInteger('position');
            $table->boolean('done')->default(false);
            $table->foreignUuid('done_by')->nullable()->constrained('iam_users');
            $table->timestampTz('done_at')->nullable();
            $audit($table);
            $table->softDeletesTz();
        });

        // §3.4 Solicitudes a proveedor (PROC-032).
        Schema::create('ops_supplier_requests', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->foreignUuid('reservation_service_id')->index()->constrained('booking_services');
            $table->foreignUuid('provider_id')->constrained('catalog_providers');
            $table->string('status', 30);
            $table->string('channel', 20);
            $table->timestampTz('requested_at');
            $table->foreignUuid('requested_by')->nullable()->constrained('iam_users');
            $table->timestampTz('responded_at')->nullable();
            $table->foreignUuid('responded_by_user_id')->nullable()->constrained('iam_users');
            $table->string('response_source', 20)->nullable();
            $table->string('confirmation_code', 40)->nullable();
            $table->string('rejection_reason', 120)->nullable();
            $table->text('note')->nullable();
            $audit($table);
            $table->softDeletesTz();
            $table->index(['tenant_id', 'provider_id', 'status']);
        });
        $checks('ops_supplier_requests', [
            'status_check' => "status IN ('solicitado','confirmado','rechazado','alternativa_propuesta','anulada')",
            'channel_check' => "channel IN ('email','extranet','telefono','integracion')",
            'source_check' => "response_source IS NULL OR response_source IN ('web_interno','extranet')",
            'reject_check' => "status <> 'rechazado' OR rejection_reason IS NOT NULL",
        ]);

        // §3.5 / §3.6 Asignaciones y check-in.
        Schema::create('ops_assignments', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->foreignUuid('order_id')->index()->constrained('ops_orders');
            $table->foreignUuid('reservation_service_id')->constrained('booking_services');
            $table->date('service_date');
            $table->time('service_time')->nullable();
            $table->string('service_label', 200);
            $table->foreignUuid('guide_id')->nullable()->constrained('ops_guides');
            $table->foreignUuid('driver_id')->nullable()->constrained('ops_drivers');
            $table->foreignUuid('vehicle_id')->nullable()->constrained('ops_vehicles');
            $table->string('status', 30)->default('pendiente');
            $table->timestampTz('confirmed_at')->nullable();
            $table->string('confirmed_via', 10)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('first_checkin_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->jsonb('no_show_passenger_ids')->nullable();
            $table->jsonb('warnings_ack')->nullable();
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique(['tenant_id', 'order_id', 'reservation_service_id']);
            $table->index(['tenant_id', 'guide_id', 'service_date']);
            $table->index(['tenant_id', 'driver_id', 'service_date']);
            $table->index(['tenant_id', 'vehicle_id', 'service_date']);
        });
        $checks('ops_assignments', [
            'status_check' => "status IN ('pendiente','asignada','confirmada','en_curso','completada')",
            'resource_check' => "status = 'pendiente' OR guide_id IS NOT NULL OR driver_id IS NOT NULL OR vehicle_id IS NOT NULL",
            'via_check' => "confirmed_via IS NULL OR confirmed_via IN ('web','app')",
        ]);

        Schema::create('ops_mobile_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('user_id')->index()->constrained('iam_users');
            $table->string('app', 20);
            $table->string('platform', 10);
            $table->string('app_version', 20);
            $table->string('expo_push_token', 200)->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('last_sync_at')->nullable();
            $table->string('sync_cursor', 64)->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX ops_mobile_devices_token_unique ON ops_mobile_devices (expo_push_token) WHERE expo_push_token IS NOT NULL');
        $checks('ops_mobile_devices', ['app_check' => "app IN ('operador','pasajero')", 'platform_check' => "platform IN ('ios','android','web')"]);

        Schema::create('ops_assignment_checkins', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('assignment_id')->index()->constrained('ops_assignments');
            $table->foreignUuid('passenger_id')->constrained('booking_passengers');
            $table->timestampTz('checked_at');
            $table->string('source', 10);
            $table->uuid('client_action_id')->nullable();
            $table->foreignUuid('device_id')->nullable()->constrained('ops_mobile_devices');
            $table->foreignUuid('checked_by')->nullable()->constrained('iam_users');
            $table->timestampTz('undone_at')->nullable();
            $table->foreignUuid('undone_by')->nullable()->constrained('iam_users');
            $table->timestampsTz();
        });
        // MOB-BR-06: idempotencia pasajero + servicio.
        DB::statement('CREATE UNIQUE INDEX ops_checkins_active_unique ON ops_assignment_checkins (assignment_id, passenger_id) WHERE undone_at IS NULL');
        $checks('ops_assignment_checkins', ['source_check' => "source IN ('app','web')"]);

        // §3.8 / §3.9 Rooming con exclusiones (RT-13, OPS-BR-30/31).
        Schema::create('ops_rooming_lists', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->foreignUuid('reservation_id')->index()->constrained('booking_reservations');
            $table->foreignUuid('hotel_provider_id')->constrained('catalog_providers');
            $table->date('check_in');
            $table->date('check_out');
            $table->string('status', 20)->default('borrador');
            $table->timestampTz('sent_at')->nullable();
            $table->foreignUuid('sent_by')->nullable()->constrained('iam_users');
            $table->integer('sent_version')->default(0);
            $table->timestampTz('hotel_ack_at')->nullable();
            $table->uuid('hotel_ack_by')->nullable();
            $table->timestampTz('hotel_confirmed_at')->nullable();
            $table->uuid('hotel_confirmed_by')->nullable();
            $table->string('source', 30)->default('manual');
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->softDeletesTz();
        });
        DB::statement("ALTER TABLE ops_rooming_lists ADD COLUMN stay daterange GENERATED ALWAYS AS (daterange(check_in, check_out, '[)')) STORED");
        DB::statement('CREATE UNIQUE INDEX ops_rooming_lists_hotel_unique ON ops_rooming_lists (tenant_id, reservation_id, hotel_provider_id) WHERE deleted_at IS NULL');
        $checks('ops_rooming_lists', [
            'status_check' => "status IN ('borrador','confirmada','modificada','enviada')",
            'dates_check' => 'check_out > check_in',
            'source_check' => "source IN ('manual','booking.lodging')",
        ]);

        Schema::create('ops_rooming_rooms', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->foreignUuid('rooming_list_id')->index()->constrained('ops_rooming_lists');
            $table->foreignUuid('hotel_provider_id')->constrained('catalog_providers');
            $table->string('room_number', 10);
            $table->string('room_type', 20);
            $table->smallInteger('capacity');
            $table->string('status', 20)->default('pendiente');
            $table->text('hotel_note')->nullable();
            $table->uuid('incident_id')->nullable();
            $audit($table);
            $table->unique(['rooming_list_id', 'room_number']);
        });
        DB::statement('ALTER TABLE ops_rooming_rooms ADD COLUMN stay daterange NOT NULL');
        DB::statement('ALTER TABLE ops_rooming_rooms ADD CONSTRAINT ops_rooming_rooms_overlap EXCLUDE USING gist (tenant_id WITH =, hotel_provider_id WITH =, room_number WITH =, stay WITH &&)');
        $checks('ops_rooming_rooms', [
            'type_check' => "room_type IN ('Simple','Doble','Doble twin','Matrimonial','Triple','Junior suite')",
            'status_check' => "status IN ('confirmada','pendiente','observada')",
        ]);

        Schema::create('ops_rooming_guests', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('room_id')->index()->constrained('ops_rooming_rooms')->cascadeOnDelete();
            $table->foreignUuid('rooming_list_id')->constrained('ops_rooming_lists');
            $table->foreignUuid('passenger_id')->constrained('booking_passengers');
            $table->timestampsTz();
            $table->unique(['rooming_list_id', 'passenger_id']);
        });
        DB::statement('ALTER TABLE ops_rooming_guests ADD COLUMN stay daterange NOT NULL');
        DB::statement('ALTER TABLE ops_rooming_guests ADD CONSTRAINT ops_rooming_guests_overlap EXCLUDE USING gist (tenant_id WITH =, passenger_id WITH =, stay WITH &&)');

        // §3.10 Vouchers.
        Schema::create('ops_vouchers', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 30);
            $table->foreignUuid('reservation_id')->index()->constrained('booking_reservations');
            $table->foreignUuid('reservation_service_id')->constrained('booking_services');
            $table->foreignUuid('provider_id')->constrained('catalog_providers');
            $table->string('service_label', 200);
            $table->date('service_date');
            $table->smallInteger('pax');
            $table->string('status', 20)->default('emitido');
            $table->integer('version')->default(1);
            $table->timestampTz('issued_at');
            $table->foreignUuid('issued_by')->nullable()->constrained('iam_users');
            $table->text('note')->nullable();
            $table->timestampTz('used_at')->nullable();
            $table->uuid('used_by')->nullable();
            $table->string('used_via', 20)->nullable();
            $table->text('void_reason')->nullable();
            $table->timestampTz('void_at')->nullable();
            $table->foreignUuid('void_by')->nullable()->constrained('iam_users');
            $table->string('origin', 20)->default('operaciones');
            $table->string('qr_token', 128)->unique();
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'provider_id', 'status']);
        });
        // OPS-BR-41: un voucher vigente por servicio.
        DB::statement("CREATE UNIQUE INDEX ops_vouchers_active_unique ON ops_vouchers (tenant_id, reservation_service_id) WHERE status = 'emitido'");
        $checks('ops_vouchers', [
            'status_check' => "status IN ('emitido','utilizado','anulado')",
            'version_check' => 'version >= 1',
            'via_check' => "used_via IS NULL OR used_via IN ('web','extranet','app_operador')",
            'origin_check' => "origin IN ('operaciones','ecommerce')",
            'void_check' => "status <> 'anulado' OR void_reason IS NOT NULL",
        ]);

        Schema::create('ops_voucher_versions', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('voucher_id')->constrained('ops_vouchers');
            $table->integer('version');
            $table->timestampTz('issued_at');
            $table->foreignUuid('issued_by')->nullable()->constrained('iam_users');
            $table->text('reason');
            $table->jsonb('snapshot');
            $table->timestampsTz();
            $table->unique(['voucher_id', 'version']);
        });

        // §3.11 Incidencias.
        Schema::create('ops_incidents', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->foreignUuid('order_id')->nullable()->index()->constrained('ops_orders');
            $table->foreignUuid('reservation_id')->nullable()->constrained('booking_reservations');
            $table->foreignUuid('reservation_service_id')->nullable()->constrained('booking_services');
            $table->foreignUuid('provider_id')->nullable()->constrained('catalog_providers');
            $table->string('type', 30);
            $table->string('severity', 10);
            $table->string('title', 200);
            $table->text('description');
            $table->string('status', 20)->default('abierta');
            $table->foreignUuid('assignee_id')->nullable()->constrained('iam_users');
            $table->foreignUuid('reported_by')->constrained('iam_users');
            $table->string('source', 20);
            $table->smallInteger('sla_hours');
            $table->timestampTz('sla_due_at');
            $table->timestampTz('sla_breached_at')->nullable();
            $table->text('resolution')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('iam_users');
            $table->timestampTz('closed_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('iam_users');
            $table->uuid('related_incident_id')->nullable();
            $table->uuid('client_action_id')->nullable();
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status', 'severity']);
            $table->index(['tenant_id', 'assignee_id']);
            $table->index(['tenant_id', 'provider_id']);
        });
        Schema::table('ops_incidents', fn (Blueprint $table) => $table->foreign('related_incident_id')->references('id')->on('ops_incidents'));
        Schema::table('ops_rooming_rooms', fn (Blueprint $table) => $table->foreign('incident_id')->references('id')->on('ops_incidents'));
        DB::statement('CREATE UNIQUE INDEX ops_incidents_action_unique ON ops_incidents (tenant_id, client_action_id) WHERE client_action_id IS NOT NULL');
        DB::statement("CREATE INDEX ops_incidents_sla_open ON ops_incidents (tenant_id, sla_due_at) WHERE status IN ('abierta','en_proceso')");
        $checks('ops_incidents', [
            'type_check' => "type IN ('Transporte','Proveedor','Salud del pasajero','Horario','Clima','Documentación','Equipaje','Seguridad','Capacidad','Pasajero','Otro')",
            'severity_check' => "severity IN ('baja','media','alta','critica')",
            'status_check' => "status IN ('abierta','en_proceso','resuelta','cerrada')",
            'source_check' => "source IN ('web','app_operador','extranet','sistema')",
        ]);

        Schema::create('ops_incident_logs', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('incident_id')->index()->constrained('ops_incidents');
            $table->foreignUuid('user_id')->nullable()->constrained('iam_users');
            $table->string('kind', 20);
            $table->text('message');
            $table->string('visibility', 10)->default('proveedor');
            $table->timestampTz('created_at')->useCurrent();
        });
        $checks('ops_incident_logs', [
            'kind_check' => "kind IN ('registro','comentario','estado','asignacion','reclasificacion','alerta','evidencia','resolucion')",
            'visibility_check' => "visibility IN ('interna','proveedor')",
        ]);

        // §3.12 Evidencias (PROC-038).
        Schema::create('ops_evidences', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('order_id')->nullable()->index()->constrained('ops_orders');
            $table->foreignUuid('reservation_service_id')->nullable()->constrained('booking_services');
            $table->foreignUuid('incident_id')->nullable()->constrained('ops_incidents');
            $table->string('file_name', 200);
            $table->string('storage_path', 400);
            $table->string('mime_type', 80);
            $table->integer('size_bytes');
            $table->char('sha256', 64);
            $table->string('source', 20);
            $table->timestampTz('captured_at');
            $table->boolean('captured_offline')->default(false);
            $table->foreignUuid('uploaded_by')->constrained('iam_users');
            $table->uuid('client_action_id')->nullable();
            $table->timestampTz('removed_at')->nullable();
            $table->foreignUuid('removed_by')->nullable()->constrained('iam_users');
            $table->text('removed_reason')->nullable();
            $table->timestampsTz();
        });
        // OPS-BR-71: deduplicación por hash dentro de la orden.
        DB::statement('CREATE UNIQUE INDEX ops_evidences_hash_unique ON ops_evidences (tenant_id, order_id, sha256) WHERE removed_at IS NULL AND order_id IS NOT NULL');
        $checks('ops_evidences', [
            'owner_check' => 'order_id IS NOT NULL OR incident_id IS NOT NULL',
            'mime_check' => "mime_type LIKE 'image/%' OR mime_type = 'application/pdf'",
            'source_check' => "source IN ('web','app_operador','extranet')",
            'removed_check' => 'removed_at IS NULL OR removed_reason IS NOT NULL',
        ]);

        // §3.13 Registro servidor del outbox móvil (base de idempotencia, MOB-BR-01).
        Schema::create('ops_sync_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('user_id')->constrained('iam_users');
            $table->foreignUuid('device_id')->nullable()->constrained('ops_mobile_devices');
            $table->string('resource_type', 10);
            $table->uuid('resource_id');
            $table->string('action_type', 20);
            $table->foreignUuid('assignment_id')->nullable()->constrained('ops_assignments');
            $table->string('coalesce_key', 120);
            $table->jsonb('payload');
            $table->timestampTz('occurred_at');
            $table->boolean('captured_offline')->default(false);
            $table->timestampTz('received_at');
            $table->smallInteger('attempts')->default(1);
            $table->string('result', 20);
            $table->string('conflict_rule', 40)->nullable();
            $table->text('conflict_message')->nullable();
            $table->jsonb('response');
            $table->index(['tenant_id', 'assignment_id', 'occurred_at']);
        });
        $checks('ops_sync_actions', [
            'type_check' => "action_type IN ('confirm','start','checkin','checkin_undo','checkin_all','evidence','incident','finish')",
            'result_check' => "result IN ('applied','duplicate','conflict_resolved','rejected')",
            'resource_check' => "resource_type IN ('guide','driver','staff')",
        ]);

        // §3.14 Chat pasajero ↔ agencia.
        Schema::create('channel_trip_messages', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('reservation_id')->constrained('booking_reservations');
            $table->string('sender_type', 10);
            $table->foreignUuid('sender_user_id')->nullable()->constrained('iam_users');
            $table->text('body');
            $table->boolean('quick_reply')->default(false);
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'reservation_id', 'created_at']);
        });
        $checks('channel_trip_messages', ['sender_check' => "sender_type IN ('passenger','agency','system')"]);

        foreach (self::TABLES as $table) {
            Rls::tenantTable($table, $table === 'ops_incident_logs' ? ['SELECT', 'INSERT'] : ['SELECT', 'INSERT', 'UPDATE', ...(in_array($table, ['ops_rooming_rooms', 'ops_rooming_guests'], true) ? ['DELETE'] : [])]);
        }

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            OpsProvisioner::seedTenant($tenantId, DB::connection());
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            if ($table === 'ops_incidents') {
                Schema::table('ops_rooming_rooms', fn (Blueprint $t) => $t->dropForeign(['incident_id']));
            }
            Schema::dropIfExists($table);
        }
        DB::statement('ALTER TABLE booking_services DROP CONSTRAINT booking_services_status_check');
        DB::statement('ALTER TABLE booking_services DROP CONSTRAINT booking_services_kind_check');
        DB::statement("ALTER TABLE booking_services ADD CONSTRAINT booking_services_status_check CHECK (status IN ('pendiente','solicitado','confirmado','rechazado','cancelado','completado'))");
        Schema::table('booking_services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by');
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['requires_assignment', 'service_kind']);
        });
    }
};
