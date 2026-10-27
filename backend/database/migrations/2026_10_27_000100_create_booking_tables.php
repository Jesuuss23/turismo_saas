<?php

use App\Services\Bookings\BookingProvisioner;
use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'booking_reservations', 'booking_passengers', 'booking_services', 'booking_changes', 'booking_cancel_requests',
        'booking_cancellations', 'fin_receivables', 'fin_payments', 'fin_invoices',
    ];

    // Spec 04 §3: reservas, pasajeros, servicios, modificaciones, anulaciones y las tablas financieras que Reservas escribe.
    // Los cupos se retienen en catalog_availability_holds (motor de disponibilidad del catálogo, reference_type = reservation).
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

        Schema::create('booking_reservations', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 30);
            $table->foreignUuid('quote_id')->nullable()->constrained('quote_quotes');
            $table->foreignUuid('quote_version_id')->nullable()->constrained('quote_versions');
            $table->smallInteger('quote_version_no')->nullable();
            $table->foreignUuid('client_id')->index()->constrained('crm_clients');
            // channel_b2b_agencies llega con Canales.
            $table->uuid('agency_id')->nullable();
            $table->foreignUuid('owner_id')->constrained('iam_users');
            $table->foreignUuid('sede_id')->nullable()->constrained('iam_sedes');
            $table->string('title', 200);
            $table->string('status', 30);
            $table->string('channel', 15);
            $table->date('travel_from');
            $table->date('travel_to');
            $table->char('currency', 3);
            $table->decimal('fx_rate', 12, 6);
            $table->decimal('total_amount', 14, 2);
            $table->string('snapshot_label', 300);
            $table->jsonb('catalog_snapshot')->nullable();
            $table->jsonb('policies_snapshot');
            $table->text('notes')->nullable();
            $table->timestampTz('rescheduled_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->decimal('penalty_amount', 14, 2)->nullable();
            $table->decimal('refund_amount', 14, 2)->nullable();
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'travel_from']);
        });
        $checks('booking_reservations', [
            'status_check' => "status IN ('borrador','pendiente_disponibilidad','confirmada','pendiente_pago','pendiente_proveedor','reprogramada','parcial','incidencia','en_operacion','completada','cancelada')",
            'channel_check' => "channel IN ('Directo','Corporativo','B2B','E-commerce')",
            'dates_check' => 'travel_to >= travel_from',
            'total_check' => 'total_amount >= 0',
            'currency_check' => "currency IN ('PEN','USD')",
        ]);
        // RT-22: una cotización genera como máximo una reserva, aunque se cancele.
        DB::statement('CREATE UNIQUE INDEX booking_reservations_quote_unique ON booking_reservations (tenant_id, quote_id) WHERE quote_id IS NOT NULL');
        Schema::table('quote_quotes', fn (Blueprint $table) => $table->foreign('reservation_id')->references('id')->on('booking_reservations'));

        Schema::create('booking_passengers', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->foreignUuid('reservation_id')->index()->constrained('booking_reservations');
            $table->smallInteger('seq');
            $table->string('full_name', 150);
            $table->string('doc_type', 12)->nullable();
            $table->string('doc_number', 30)->nullable();
            $table->char('pax_type', 3);
            $table->string('nationality', 60)->nullable();
            $table->date('birth_date')->nullable();
            $table->smallInteger('age')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('relationship', 30)->nullable();
            $table->boolean('is_lead')->default(false);
            $table->string('diet_notes', 200)->nullable();
            $table->boolean('is_placeholder')->default(true);
            $audit($table);
        });
        $checks('booking_passengers', [
            'doc_type_check' => "doc_type IS NULL OR doc_type IN ('DNI','Pasaporte','CE','Passport')",
            'pax_type_check' => "pax_type IN ('ADT','CHD','INF')",
            'relationship_check' => "relationship IS NULL OR relationship IN ('Titular','Cónyuge / pareja','Hijo/a','Padre / madre','Hermano/a','Amigo/a','Otro')",
        ]);
        // RES-V21-04: un solo titular por reserva.
        DB::statement('CREATE UNIQUE INDEX booking_passengers_lead_unique ON booking_passengers (reservation_id) WHERE is_lead');

        Schema::create('booking_services', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->foreignUuid('reservation_id')->constrained('booking_reservations');
            $table->string('code', 20);
            $table->foreignUuid('product_id')->nullable()->constrained('catalog_products');
            $table->foreignUuid('catalog_service_id')->nullable()->constrained('catalog_services');
            $table->foreignUuid('quote_item_id')->nullable()->constrained('quote_items');
            $table->foreignUuid('departure_id')->nullable()->constrained('catalog_product_departures');
            $table->foreignUuid('provider_id')->nullable()->constrained('catalog_providers');
            $table->string('name', 200);
            $table->date('service_date');
            $table->time('service_time')->nullable();
            $table->integer('pax')->nullable();
            $table->string('status', 20)->default('pendiente');
            $table->decimal('cost_amount', 14, 2);
            $table->decimal('price_amount', 14, 2);
            $table->boolean('is_required')->default(true);
            $table->string('confirmation_code', 40)->nullable();
            $table->string('confirmed_by_name', 120)->nullable();
            $table->timestampTz('requested_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->uuid('alt_of_id')->nullable();
            $table->uuid('replaced_by_id')->nullable();
            $audit($table);
            $table->unique(['tenant_id', 'code']);
            $table->index(['reservation_id', 'service_date']);
        });
        Schema::table('booking_services', function (Blueprint $table) {
            $table->foreign('alt_of_id')->references('id')->on('booking_services');
            $table->foreign('replaced_by_id')->references('id')->on('booking_services');
        });
        $checks('booking_services', [
            'status_check' => "status IN ('pendiente','solicitado','confirmado','rechazado','cancelado','completado')",
            'amounts_check' => 'cost_amount >= 0 AND price_amount >= 0',
            'confirmed_check' => "status <> 'confirmado' OR confirmation_code IS NOT NULL",
        ]);

        Schema::create('booking_changes', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('reservation_id')->index()->constrained('booking_reservations');
            $table->string('type', 20);
            $table->text('description');
            $table->integer('extra_pax')->nullable();
            $table->foreignUuid('product_id')->nullable()->constrained('catalog_products');
            $table->date('service_date')->nullable();
            $table->decimal('delta_amount', 14, 2);
            $table->decimal('credit_amount', 14, 2)->nullable();
            $table->string('status', 15);
            $table->foreignUuid('requested_by')->constrained('iam_users');
            $table->timestampTz('requested_at');
            $table->foreignUuid('decided_by')->nullable()->constrained('iam_users');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestampsTz();
        });
        $checks('booking_changes', [
            'type_check' => "type IN ('pax','servicio','upgrade','otro','reprogramacion')",
            'status_check' => "status IN ('pendiente','aplicada','rechazada')",
            'pax_check' => "type <> 'pax' OR extra_pax >= 1",
        ]);

        Schema::create('booking_cancel_requests', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('reservation_id')->index()->constrained('booking_reservations');
            $table->text('reason');
            $table->foreignUuid('requested_by')->constrained('iam_users');
            $table->timestampTz('requested_at');
            $table->string('status', 15)->default('pendiente');
            $table->foreignUuid('decided_by')->nullable()->constrained('iam_users');
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestampsTz();
        });
        $checks('booking_cancel_requests', ['status_check' => "status IN ('pendiente','aprobada','rechazada')"]);
        // RES-BR-33: una solicitud pendiente por reserva.
        DB::statement("CREATE UNIQUE INDEX booking_cancel_requests_pending_unique ON booking_cancel_requests (reservation_id) WHERE status = 'pendiente'");

        Schema::create('fin_receivables', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->foreignUuid('reservation_id')->nullable()->index()->constrained('booking_reservations');
            $table->foreignUuid('client_id')->index()->constrained('crm_clients');
            $table->string('concept', 200);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->date('due_date');
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->string('status', 15)->default('abierta');
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status', 'due_date']);
        });
        $checks('fin_receivables', [
            'status_check' => "status IN ('abierta','parcial','pagada','vencida','cancelada')",
            'amount_check' => 'amount >= 0 AND paid_amount >= 0',
        ]);

        Schema::create('fin_invoices', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->string('type', 30);
            $table->string('status', 15);
            $table->foreignUuid('reservation_id')->nullable()->index()->constrained('booking_reservations');
            $table->foreignUuid('client_id')->index()->constrained('crm_clients');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->date('issued_on');
            $table->text('detail')->nullable();
            $audit($table);
            $table->unique(['tenant_id', 'code']);
        });
        $checks('fin_invoices', [
            'type_check' => "type IN ('Factura','Boleta','Nota de crédito')",
            'status_check' => "status IN ('borrador','emitido','anulado')",
        ]);

        Schema::create('fin_payments', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->foreignUuid('receivable_id')->nullable()->index()->constrained('fin_receivables');
            $table->foreignUuid('reservation_id')->nullable()->index()->constrained('booking_reservations');
            $table->foreignUuid('client_id')->constrained('crm_clients');
            $table->string('method', 60);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->date('paid_on');
            $table->string('status', 15);
            $table->string('reference', 200)->nullable();
            $table->string('kind', 10)->default('cobro');
            $table->foreignUuid('credit_note_id')->nullable()->constrained('fin_invoices');
            $table->foreignUuid('registered_by')->nullable()->constrained('iam_users');
            $audit($table);
            $table->unique(['tenant_id', 'code']);
        });
        $checks('fin_payments', [
            'status_check' => "status IN ('pendiente','confirmado','aplicado','revertido')",
            'kind_check' => "kind IN ('cobro','reembolso')",
            'amount_check' => 'amount > 0',
        ]);

        Schema::create('booking_cancellations', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('reservation_id')->unique()->constrained('booking_reservations');
            $table->decimal('total_at_cancel', 14, 2);
            $table->decimal('paid_at_cancel', 14, 2);
            $table->decimal('penalty_services', 14, 2);
            $table->decimal('consumed_amount', 14, 2);
            $table->decimal('factor_k', 12, 6);
            $table->decimal('penalty_amount', 14, 2);
            $table->decimal('refund_amount', 14, 2);
            $table->decimal('owed_amount', 14, 2);
            $table->jsonb('lines');
            $table->foreignUuid('credit_note_id')->nullable()->constrained('fin_invoices');
            $table->foreignUuid('refund_payment_id')->nullable()->constrained('fin_payments');
            $table->foreignUuid('penalty_receivable_id')->nullable()->constrained('fin_receivables');
            $table->text('reason');
            $table->foreignUuid('cancelled_by')->constrained('iam_users');
            $table->timestampTz('cancelled_at');
            $table->timestampsTz();
        });

        foreach (self::TABLES as $table) {
            Rls::tenantTable($table);
        }

        // Correlativos del módulo y permiso de reasignación (§7, RES-BR-51) en los tenants existentes.
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            BookingProvisioner::seedTenant($tenantId, DB::connection());
        }
        $permission = DB::table('permissions')->where('name', 'reservations.reassign')->where('guard_name', 'api')->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => 'reservations.reassign', 'guard_name' => 'api', 'module' => 'Reservas',
                'description' => 'Reasignar el asesor de una reserva',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        foreach (DB::table('roles')->whereIn('name', ['tenant_admin', 'manager'])->pluck('id') as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permission, 'role_id' => $roleId]);
        }
    }

    public function down(): void
    {
        Schema::table('quote_quotes', fn (Blueprint $table) => $table->dropForeign(['reservation_id']));
        Schema::dropIfExists('booking_cancellations');
        Schema::dropIfExists('fin_payments');
        Schema::dropIfExists('fin_invoices');
        Schema::dropIfExists('fin_receivables');
        foreach (['booking_cancel_requests', 'booking_changes', 'booking_services', 'booking_passengers', 'booking_reservations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
