<?php

use App\Security\PermissionCatalog;
use App\Services\Supplier\SupplierProvisioner;
use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['fin_payables', 'supplier_request_responses', 'supplier_invoices', 'supplier_rate_requests', 'catalog_service_rates'];

    // Spec 06 §3: extranet de proveedores. `fin_payables` es la base mínima de cuentas por pagar que la extranet
    // necesita (el módulo de Finanzas la ampliará); `catalog_service_rates` guarda las nuevas vigencias de tarifa.
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

        // §3.1 Sub-rol dentro del proveedor (§7).
        Schema::table('iam_users', function (Blueprint $table) {
            $table->string('provider_role', 20)->nullable();
            $table->index(['tenant_id', 'provider_id']);
        });
        $checks('iam_users', ['provider_role_check' => "provider_role IS NULL OR provider_role IN ('admin','reservas','recepcion','contabilidad')"]);

        Schema::create('fin_payables', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->foreignUuid('provider_id')->constrained('catalog_providers');
            $table->foreignUuid('reservation_id')->nullable()->index()->constrained('booking_reservations');
            $table->foreignUuid('reservation_service_id')->nullable()->constrained('booking_services');
            $table->string('concept', 200);
            $table->decimal('amount', 14, 2);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->char('currency', 3);
            $table->date('due_date');
            $table->string('status', 15)->default('abierta');
            $table->timestampTz('paid_at')->nullable();
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'provider_id', 'status']);
        });
        $checks('fin_payables', [
            'status_check' => "status IN ('abierta','aprobada','parcial','pagada','vencida','anulada')",
            'amount_check' => 'amount >= 0 AND paid_amount >= 0 AND paid_amount <= amount',
            'currency_check' => "currency IN ('PEN','USD')",
        ]);
        DB::statement("CREATE UNIQUE INDEX fin_payables_service_unique ON fin_payables (tenant_id, reservation_service_id) WHERE reservation_service_id IS NOT NULL AND status <> 'anulada'");

        // §3.2 Respuesta del proveedor a una solicitud.
        Schema::create('supplier_request_responses', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->foreignUuid('supplier_request_id')->index()->constrained('ops_supplier_requests');
            $table->foreignUuid('reservation_service_id')->constrained('booking_services');
            $table->foreignUuid('provider_id')->constrained('catalog_providers');
            $table->string('type', 20);
            $table->foreignUuid('responded_by_user_id')->constrained('iam_users');
            $table->timestampTz('responded_at');
            $table->string('confirmed_by_name', 120)->nullable();
            $table->string('confirmation_code', 40)->nullable();
            $table->string('rejection_reason', 60)->nullable();
            $table->string('alt_kind', 40)->nullable();
            $table->date('alt_date')->nullable();
            $table->foreignUuid('alt_service_id')->nullable()->constrained('catalog_services');
            $table->decimal('alt_net_cost', 14, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->date('hold_until')->nullable();
            $table->text('note')->nullable();
            $table->string('review_status', 20)->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('iam_users');
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $audit($table);
        });
        DB::statement('CREATE INDEX supplier_request_responses_provider_idx ON supplier_request_responses (tenant_id, provider_id, created_at DESC)');
        $checks('supplier_request_responses', [
            'type_check' => "type IN ('confirmacion','rechazo','alternativa')",
            'reason_check' => "rejection_reason IS NULL OR rejection_reason IN ('Sin disponibilidad (sold out)','Fecha bloqueada por mantenimiento','Tarifa no vigente para la fecha','Cupo de grupo excedido','Otro')",
            'alt_kind_check' => "alt_kind IS NULL OR alt_kind IN ('Otra fecha','Otro tipo de habitación','Otro horario','Upgrade sin costo','Otro')",
            'review_check' => "review_status IS NULL OR review_status IN ('pendiente','aceptada','rechazada','expirada')",
            'confirm_check' => "type <> 'confirmacion' OR (confirmation_code IS NOT NULL AND length(confirmation_code) >= 4)",
            'reject_check' => "type <> 'rechazo' OR (rejection_reason IS NOT NULL AND note IS NOT NULL)",
            'alternative_check' => "type <> 'alternativa' OR (alt_kind IS NOT NULL AND note IS NOT NULL AND review_status IS NOT NULL)",
            'cost_check' => 'alt_net_cost IS NULL OR alt_net_cost >= 0',
        ]);

        // §3.3 Factura del proveedor. El archivo vive en el disco de documentos (S3 en producción).
        Schema::create('supplier_invoices', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('payable_id')->index()->constrained('fin_payables');
            $table->foreignUuid('provider_id')->constrained('catalog_providers');
            $table->string('number', 20);
            $table->date('issue_date');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->decimal('amount_difference', 14, 2);
            $table->string('storage_path', 300);
            $table->string('mime_type', 100);
            $table->integer('size_bytes');
            $table->char('sha256', 64);
            $table->string('original_file_name', 200);
            $table->string('status', 20)->default('registrada');
            $table->foreignUuid('uploaded_by')->constrained('iam_users');
            $table->timestampTz('uploaded_at');
            $table->foreignUuid('validated_by')->nullable()->constrained('iam_users');
            $table->timestampTz('validated_at')->nullable();
            $table->text('validation_note')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'provider_id', 'number']);
        });
        $checks('supplier_invoices', [
            'number_check' => "number ~ '^[A-Z0-9]{4}-[0-9]{1,8}$'",
            'amount_check' => 'amount > 0',
            'status_check' => "status IN ('registrada','validada','observada','rechazada')",
        ]);
        // Una factura activa por CxP (SUP-BR-42).
        DB::statement("CREATE UNIQUE INDEX supplier_invoices_active_unique ON supplier_invoices (tenant_id, payable_id) WHERE status IN ('registrada','validada') AND deleted_at IS NULL");

        // Nuevas vigencias de costo de un servicio (se aplican al llegar `valid_from`; PRD-BR-008/009).
        Schema::create('catalog_service_rates', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->foreignUuid('service_id')->index()->constrained('catalog_services');
            $table->decimal('cost', 14, 2);
            $table->char('currency', 3);
            $table->date('valid_from');
            $table->string('source', 20);
            $table->timestampTz('applied_at')->nullable();
            $audit($table);
        });
        $checks('catalog_service_rates', ['cost_check' => 'cost > 0', 'source_check' => "source IN ('manual','solicitud_proveedor')"]);

        // §3.4 Solicitud de actualización de tarifa.
        Schema::create('supplier_rate_requests', function (Blueprint $table) use ($base) {
            $base($table);
            $table->string('code', 20);
            $table->foreignUuid('provider_id')->constrained('catalog_providers');
            $table->foreignUuid('service_id')->constrained('catalog_services');
            $table->decimal('current_cost', 14, 2);
            $table->decimal('proposed_cost', 14, 2);
            $table->char('currency', 3);
            $table->date('effective_from');
            $table->string('reason_type', 40);
            $table->text('reason_detail')->nullable();
            $table->string('status', 20)->default('pendiente');
            $table->foreignUuid('requested_by')->constrained('iam_users');
            $table->timestampTz('requested_at');
            $table->foreignUuid('reviewed_by')->nullable()->constrained('iam_users');
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignUuid('resulting_rate_id')->nullable()->constrained('catalog_service_rates');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'provider_id', 'status']);
        });
        $checks('supplier_rate_requests', [
            'cost_check' => 'proposed_cost > 0',
            'reason_check' => "reason_type IN ('Ajuste por inflación / costos','Cambio de temporada','Mejora del servicio','Tarifa promocional','Otro')",
            'status_check' => "status IN ('pendiente','aprobada','rechazada','retirada')",
        ]);
        DB::statement("CREATE UNIQUE INDEX supplier_rate_requests_pending_unique ON supplier_rate_requests (tenant_id, service_id) WHERE status = 'pendiente'");

        foreach (self::TABLES as $table) {
            Rls::tenantTable($table);
        }

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            SupplierProvisioner::seedTenant($tenantId, DB::connection());
        }

        // Permisos de la extranet (§7) en bases existentes: el rol Proveedor (plantilla y copias por tenant) los recibe todos.
        foreach (PermissionCatalog::GROUPS['Extranet'] as $name => $description) {
            $id = DB::table('permissions')->where('name', $name)->where('guard_name', PermissionCatalog::GUARD)->value('id')
                ?? DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => PermissionCatalog::GUARD, 'module' => 'Extranet',
                    'description' => $description, 'created_at' => now(), 'updated_at' => now()]);
            foreach (DB::table('roles')->where('name', 'provider')->pluck('id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $id, 'role_id' => $roleId]);
            }
        }
    }

    public function down(): void
    {
        foreach (['supplier_rate_requests', 'catalog_service_rates', 'supplier_invoices', 'supplier_request_responses', 'fin_payables'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('ALTER TABLE iam_users DROP CONSTRAINT IF EXISTS iam_users_provider_role_check');
        Schema::table('iam_users', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'provider_id']);
            $table->dropColumn('provider_role');
        });
    }
};
