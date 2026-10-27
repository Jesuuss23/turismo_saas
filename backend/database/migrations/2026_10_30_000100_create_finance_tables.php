<?php

use App\Services\Finance\FinanceProvisioner;
use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'channel_b2b_agencies', 'fin_receivable_reminders', 'fin_payment_applications', 'fin_status_history', 'fin_bank_accounts', 'fin_payment_methods',
        'fin_payable_payouts', 'fin_commission_rules', 'fin_commissions', 'fin_settlements', 'fin_document_series', 'fin_invoice_lines', 'fin_fiscal_events',
        'fin_bank_statements', 'fin_reconciliation_closures', 'fin_bank_lines', 'fin_period_closes', 'fin_period_close_checks', 'fin_fx_rates',
    ];

    // Spec 07 §3: Finanzas y Contabilidad. Amplía las tablas que crearon Reservas (CxC, pagos, comprobantes) y la extranet (CxP)
    // y agrega aplicaciones, payouts, comisiones, liquidaciones, series, conciliación, cierre de periodo y tipos de cambio.
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

        // Agencias B2B (§3.20): base mínima de Canales para el crédito B2B y el % de convenio (antes en tenants.settings).
        Schema::create('channel_b2b_agencies', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->string('name', 200);
            $table->foreignUuid('client_id')->nullable()->constrained('crm_clients');
            $table->string('status', 15)->default('activa');
            $table->decimal('credit_limit', 14, 2)->default(0);
            $table->decimal('credit_used', 14, 2)->default(0);
            $table->decimal('commission_pct', 5, 2)->default(0);
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique(['tenant_id', 'code']);
        });
        $checks('channel_b2b_agencies', ['status_check' => "status IN ('activa','bloqueada','inactiva')", 'credit_check' => 'credit_limit >= 0 AND credit_used >= 0']);

        // §3.1 CxC.
        Schema::table('fin_receivables', function (Blueprint $table) {
            $table->string('kind', 20)->default('saldo');
            $table->decimal('fx_rate', 12, 6)->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestampTz('last_reminder_at')->nullable();
            $table->softDeletesTz();
            $table->index(['tenant_id', 'client_id']);
            $table->index(['tenant_id', 'created_at']);
        });
        $checks('fin_receivables', [
            'kind_check' => "kind IN ('anticipo','saldo','cargo','ajuste','penalidad','b2b')",
            'amount_check' => 'amount >= 0 AND paid_amount >= 0 AND paid_amount <= amount + 0.01',
            'fx_check' => "currency = 'PEN' OR fx_rate IS NOT NULL",
        ]);
        DB::statement("CREATE INDEX fin_receivables_open_due ON fin_receivables (tenant_id, due_date) WHERE status IN ('abierta','parcial','vencida')");

        // §3.2
        Schema::create('fin_receivable_reminders', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('receivable_id')->index()->constrained('fin_receivables');
            $table->string('channels', 60);
            $table->foreignUuid('sent_by')->nullable()->constrained('iam_users');
            $table->timestampTz('sent_at');
            $table->decimal('balance_at_send', 14, 2);
        });

        // §3.3 Pagos.
        Schema::table('fin_payments', function (Blueprint $table) {
            $table->string('source', 20)->default('backoffice');
            $table->decimal('fx_rate', 12, 6)->nullable();
            $table->text('note')->nullable();
            $table->uuid('evidence_document_id')->nullable();
            $table->text('reject_reason')->nullable();
            $table->smallInteger('retries')->default(0);
            $table->timestampTz('reverted_at')->nullable();
            $table->foreignUuid('reverted_by')->nullable()->constrained('iam_users');
            $table->text('revert_reason')->nullable();
            $table->integer('lock_version')->default(0);
            $table->index(['tenant_id', 'status']);
        });
        $checks('fin_payments', [
            'status_check' => "status IN ('pendiente','confirmado','aplicado','rechazado','revertido')",
            'source_check' => "source IN ('backoffice','reserva','portal_cliente','b2b','ecommerce','sistema')",
            'reject_check' => "status <> 'rechazado' OR reject_reason IS NOT NULL",
            'revert_check' => "status <> 'revertido' OR (reverted_at IS NOT NULL AND revert_reason IS NOT NULL)",
            'fx_check' => "currency = 'PEN' OR fx_rate IS NOT NULL OR kind = 'reembolso'",
        ]);

        // §3.4
        Schema::create('fin_payment_applications', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('payment_id')->constrained('fin_payments');
            $table->foreignUuid('receivable_id')->index()->constrained('fin_receivables');
            $table->decimal('amount', 14, 2);
            $table->timestampTz('applied_at');
            $table->foreignUuid('applied_by')->nullable()->constrained('iam_users');
            $table->timestampTz('reversed_at')->nullable();
            $table->foreignUuid('reversed_by')->nullable()->constrained('iam_users');
        });
        DB::statement('CREATE UNIQUE INDEX fin_payment_applications_active_unique ON fin_payment_applications (payment_id) WHERE reversed_at IS NULL');

        // §3.5
        Schema::create('fin_status_history', function (Blueprint $table) use ($base) {
            $base($table);
            $table->string('entity_type', 30);
            $table->uuid('entity_id');
            $table->string('status', 30);
            $table->text('note')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('iam_users');
            $table->timestampTz('created_at');
            $table->index(['tenant_id', 'entity_type', 'entity_id']);
        });
        $checks('fin_status_history', ['entity_check' => "entity_type IN ('payment','commission','invoice','payable')"]);

        // §3.8 / §3.9
        Schema::create('fin_bank_accounts', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('bank', 20);
            $table->string('account_type', 20);
            $table->char('currency', 3);
            $table->string('number', 30);
            $table->string('cci', 20)->nullable();
            $table->string('label', 80);
            $table->boolean('active')->default(true);
            $audit($table);
        });
        $checks('fin_bank_accounts', ['type_check' => "account_type IN ('cta_cte','ahorros')"]);
        Schema::create('fin_payment_methods', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 40);
            $table->string('label', 80);
            $table->boolean('requires_reference')->default(true);
            $table->boolean('is_b2b_credit')->default(false);
            $table->boolean('active')->default(true);
            $audit($table);
            $table->unique(['tenant_id', 'code']);
        });

        // §3.6 CxP y costos (la extranet creó la base; `anulada` pasa a `cancelada`).
        DB::statement('DROP INDEX IF EXISTS fin_payables_service_unique');
        DB::statement('ALTER TABLE fin_payables DROP CONSTRAINT IF EXISTS fin_payables_status_check');
        DB::table('fin_payables')->where('status', 'anulada')->update(['status' => 'cancelada']);
        Schema::table('fin_payables', function (Blueprint $table) {
            $table->string('kind', 20)->default('servicio');
            $table->string('cost_type', 40)->nullable();
            $table->decimal('fx_rate', 12, 6)->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('iam_users');
            $table->string('supplier_doc_number', 30)->nullable();
            $table->text('evidence_note')->nullable();
            $table->date('scheduled_date')->nullable();
            $table->foreignUuid('scheduled_bank_account_id')->nullable()->constrained('fin_bank_accounts');
            $table->foreignUuid('scheduled_by')->nullable()->constrained('iam_users');
            $table->softDeletesTz();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'due_date']);
            $table->index(['tenant_id', 'kind']);
        });
        $checks('fin_payables', [
            'status_check' => "status IN ('abierta','aprobada','parcial','pagada','vencida','cancelada')",
            'kind_check' => "kind IN ('servicio','costo_adicional')",
            'cost_type_check' => "(kind = 'servicio' AND cost_type IS NULL) OR (kind = 'costo_adicional' AND cost_type IN ('costo_adicional','ajuste_tarifa','penalidad_proveedor','gasto_operativo_destino'))",
        ]);
        DB::statement("CREATE UNIQUE INDEX fin_payables_service_unique ON fin_payables (tenant_id, reservation_service_id) WHERE reservation_service_id IS NOT NULL AND kind = 'servicio' AND status <> 'cancelada'");

        // §3.7
        Schema::create('fin_payable_payouts', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->foreignUuid('payable_id')->index()->constrained('fin_payables');
            $table->date('paid_on');
            $table->decimal('amount', 14, 2);
            $table->decimal('fx_rate', 12, 6)->nullable();
            $table->string('method', 30);
            $table->foreignUuid('bank_account_id')->nullable()->constrained('fin_bank_accounts');
            $table->string('operation_ref', 80);
            $table->uuid('bank_line_id')->nullable();
            $table->foreignUuid('paid_by')->nullable()->constrained('iam_users');
            $audit($table);
            $table->unique(['tenant_id', 'code']);
        });
        $checks('fin_payable_payouts', [
            'method_check' => "method IN ('transferencia','transferencia_cci','cheque_gerencia','efectivo_caja_chica','cargo_bancario')",
            'amount_check' => 'amount > 0',
        ]);

        // §3.10 / §3.11 Comisiones.
        Schema::create('fin_commission_rules', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('beneficiary_kind', 20);
            $table->string('condition', 30);
            $table->string('label', 120);
            $table->decimal('pct', 5, 2)->nullable();
            $table->string('base_type', 20);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->boolean('active')->default(true);
            $audit($table);
        });
        $checks('fin_commission_rules', [
            'kind_check' => "beneficiary_kind IN ('vendedor','agencia_b2b')",
            'condition_check' => "condition IN ('directo_ecommerce','corporativo_grupos','b2b_convenio')",
            'base_check' => "base_type IN ('neto_sin_igv','total')",
        ]);
        Schema::create('fin_commissions', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->foreignUuid('reservation_id')->constrained('booking_reservations');
            $table->string('kind', 20);
            $table->foreignUuid('beneficiary_user_id')->nullable()->constrained('iam_users');
            $table->foreignUuid('beneficiary_agency_id')->nullable()->constrained('channel_b2b_agencies');
            $table->foreignUuid('rule_id')->nullable()->constrained('fin_commission_rules');
            $table->string('rule_description', 255);
            $table->decimal('base_amount', 14, 2);
            $table->decimal('pct', 5, 2);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->decimal('fx_rate', 12, 6)->nullable();
            $table->string('status', 20)->default('calculada');
            $table->timestampTz('approved_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('iam_users');
            $table->timestampTz('paid_at')->nullable();
            $table->string('payment_method', 40)->nullable();
            $table->string('payment_ref', 80)->nullable();
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });
        $checks('fin_commissions', [
            'kind_check' => "kind IN ('vendedor','agencia_b2b')",
            'status_check' => "status IN ('calculada','aprobada','pagada')",
            'beneficiary_check' => '(beneficiary_user_id IS NULL) <> (beneficiary_agency_id IS NULL)',
        ]);
        DB::statement('CREATE UNIQUE INDEX fin_commissions_reservation_unique ON fin_commissions (tenant_id, reservation_id, kind) WHERE deleted_at IS NULL');

        // §3.12
        Schema::create('fin_settlements', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->foreignUuid('reservation_id')->constrained('booking_reservations');
            $table->timestampTz('closed_at');
            $table->foreignUuid('closed_by')->constrained('iam_users');
            $table->decimal('income_pen', 14, 2);
            $table->decimal('cost_pen', 14, 2);
            $table->decimal('commissions_pen', 14, 2);
            $table->decimal('net_pen', 14, 2);
            $table->decimal('gross_margin_pct', 6, 2);
            $table->jsonb('snapshot');
            $audit($table);
            $table->unique(['tenant_id', 'reservation_id']);
        });

        // §3.13 Series.
        Schema::create('fin_document_series', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->char('doc_type', 2);
            $table->char('series', 4);
            $table->integer('next_number')->default(1);
            $table->foreignUuid('sede_id')->nullable()->constrained('iam_sedes');
            $table->boolean('active')->default(true);
            $audit($table);
            $table->unique(['tenant_id', 'series']);
        });
        $checks('fin_document_series', ['doc_type_check' => "doc_type IN ('01','03','07','08')"]);

        // §3.14 Comprobantes (Reservas creó la base con `code/type/amount/issued_on/detail`).
        DB::statement('ALTER TABLE fin_invoices DROP CONSTRAINT IF EXISTS fin_invoices_type_check');
        DB::statement('ALTER TABLE fin_invoices DROP CONSTRAINT IF EXISTS fin_invoices_status_check');
        Schema::table('fin_invoices', function (Blueprint $table) {
            $table->renameColumn('code', 'number');
            $table->renameColumn('amount', 'total_amount');
            $table->renameColumn('issued_on', 'issue_date');
            $table->renameColumn('detail', 'description');
        });
        Schema::table('fin_invoices', function (Blueprint $table) {
            $table->dropColumn('type');
        });
        Schema::table('fin_invoices', function (Blueprint $table) {
            $table->char('doc_type', 2)->default('01');
            $table->char('series', 4)->default('F001');
            $table->integer('correlative')->default(0);
            $table->char('period', 7)->default('2026-01');
            $table->string('customer_doc_type', 10)->default('SIN_DOC');
            $table->string('customer_doc_number', 20)->default('-');
            $table->string('customer_name', 200)->default('');
            $table->string('customer_address', 255)->nullable();
            $table->boolean('customer_domiciled')->default(true);
            $table->foreignUuid('receivable_id')->nullable()->index()->constrained('fin_receivables');
            $table->string('operation_type', 20)->default('gravada');
            $table->string('sunat_operation_code', 4)->nullable();
            $table->decimal('fx_rate', 12, 6)->nullable();
            $table->decimal('taxable_amount', 14, 2)->default(0);
            $table->decimal('igv_amount', 14, 2)->default(0);
            $table->decimal('exempt_amount', 14, 2)->default(0);
            $table->string('amount_in_words', 255)->default('');
            $table->foreignUuid('ref_invoice_id')->nullable()->constrained('fin_invoices');
            $table->char('credit_note_reason_code', 2)->nullable();
            $table->string('credit_note_reason', 255)->nullable();
            $table->string('fiscal_provider', 30)->nullable();
            $table->string('ose_ticket', 80)->nullable();
            $table->string('ose_response_code', 10)->nullable();
            $table->text('ose_response_message')->nullable();
            $table->timestampTz('ose_responded_at')->nullable();
            $table->uuid('xml_document_id')->nullable();
            $table->uuid('cdr_document_id')->nullable();
            $table->uuid('pdf_document_id')->nullable();
            $table->string('hash_code', 64)->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->foreignUuid('issued_by')->nullable()->constrained('iam_users');
            $table->integer('lock_version')->default(0);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'doc_type']);
            $table->index(['tenant_id', 'issue_date']);
            $table->unique(['tenant_id', 'series', 'correlative']);
        });
        $checks('fin_invoices', [
            'doc_type_check' => "doc_type IN ('01','03','07','08')",
            'status_check' => "status IN ('pendiente_envio','emitido','aceptado','observado','rechazado','anulado')",
            'doc_check' => "customer_doc_type IN ('RUC','DNI','PASAPORTE','CE','SIN_DOC')",
            'operation_check' => "operation_type IN ('gravada','exportacion')",
            'ref_check' => "doc_type NOT IN ('07','08') OR ref_invoice_id IS NOT NULL",
            'total_check' => 'total_amount > 0',
            'fx_check' => "currency = 'PEN' OR fx_rate IS NOT NULL",
        ]);

        // §3.15 / §3.16
        Schema::create('fin_invoice_lines', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('invoice_id')->index()->constrained('fin_invoices');
            $table->smallInteger('line_no');
            $table->string('description', 500);
            $table->decimal('quantity', 12, 3)->default(1);
            $table->string('unit_code', 3)->default('ZZ');
            $table->decimal('unit_value', 14, 2);
            $table->string('igv_affectation', 2);
            $table->decimal('line_amount', 14, 2);
            $table->decimal('igv_amount', 14, 2);
        });
        Schema::create('fin_fiscal_events', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('invoice_id')->index()->constrained('fin_invoices');
            $table->string('action', 20);
            $table->smallInteger('attempt')->default(1);
            $table->string('request_hash', 64)->nullable();
            $table->string('response_code', 10)->nullable();
            $table->text('response_message')->nullable();
            $table->uuid('payload_document_id')->nullable();
            $table->string('status', 20);
            $table->timestampTz('created_at');
        });
        $checks('fin_fiscal_events', [
            'action_check' => "action IN ('send','status','resend','void')",
            'status_check' => "status IN ('ok','error_temporal','error_definitivo')",
        ]);

        // §3.17 Conciliación.
        Schema::create('fin_bank_statements', function (Blueprint $table) use ($base) {
            $base($table);
            $table->string('bank', 20);
            $table->foreignUuid('bank_account_id')->nullable()->constrained('fin_bank_accounts');
            $table->string('filename', 200);
            $table->uuid('document_id')->nullable();
            $table->string('format', 10);
            $table->integer('lines_count');
            $table->foreignUuid('imported_by')->nullable()->constrained('iam_users');
            $table->timestampTz('imported_at');
        });
        Schema::create('fin_reconciliation_closures', function (Blueprint $table) use ($base) {
            $base($table);
            $table->string('code', 20);
            $table->char('period', 7);
            $table->timestampTz('closed_at');
            $table->foreignUuid('closed_by')->constrained('iam_users');
            $table->integer('lines_count');
            $table->decimal('credits', 14, 2);
            $table->decimal('debits', 14, 2);
            $table->decimal('net', 14, 2);
            $table->integer('matched_count');
            $table->integer('others_count');
            $table->unique(['tenant_id', 'code']);
        });
        Schema::create('fin_bank_lines', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->foreignUuid('statement_id')->nullable()->constrained('fin_bank_statements');
            $table->string('bank', 20);
            $table->date('value_date');
            $table->string('description', 255);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('PEN');
            $table->foreignUuid('matched_payment_id')->nullable()->constrained('fin_payments');
            $table->foreignUuid('matched_payable_id')->nullable()->constrained('fin_payables');
            $table->string('status', 20)->default('pendiente');
            $table->string('resolution', 20)->nullable();
            $table->text('resolution_note')->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('iam_users');
            $table->foreignUuid('closure_id')->nullable()->constrained('fin_reconciliation_closures');
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });
        $checks('fin_bank_lines', [
            'status_check' => "status IN ('pendiente','sugerido','excepcion','conciliado')",
            'resolution_check' => "resolution IS NULL OR resolution IN ('match','asignado','otro_ingreso','gasto_bancario')",
            'amount_check' => 'amount <> 0',
            'match_check' => 'matched_payment_id IS NULL OR matched_payable_id IS NULL',
        ]);
        DB::statement("CREATE UNIQUE INDEX fin_bank_lines_payment_unique ON fin_bank_lines (tenant_id, matched_payment_id) WHERE matched_payment_id IS NOT NULL AND status <> 'excepcion'");
        DB::statement("CREATE UNIQUE INDEX fin_bank_lines_payable_unique ON fin_bank_lines (tenant_id, matched_payable_id) WHERE matched_payable_id IS NOT NULL AND status <> 'excepcion'");
        Schema::table('fin_payable_payouts', function (Blueprint $table) {
            $table->foreign('bank_line_id')->references('id')->on('fin_bank_lines');
        });

        // §3.18 Cierre de periodo.
        Schema::create('fin_period_closes', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->char('period', 7);
            $table->string('status', 10)->default('abierto');
            $table->timestampTz('closed_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('iam_users');
            $table->jsonb('snapshot')->nullable();
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique(['tenant_id', 'period']);
        });
        $checks('fin_period_closes', ['status_check' => "status IN ('abierto','cerrado')"]);
        Schema::create('fin_period_close_checks', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('period_close_id')->constrained('fin_period_closes');
            $table->string('check_code', 20);
            $table->foreignUuid('checked_by')->constrained('iam_users');
            $table->timestampTz('checked_at');
            $table->unique(['period_close_id', 'check_code']);
        });
        $checks('fin_period_close_checks', ['code_check' => "check_code IN ('fx','aging','igv')"]);

        // §3.19
        Schema::create('fin_fx_rates', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->date('rate_date');
            $table->char('currency', 3);
            $table->decimal('buy', 12, 6);
            $table->decimal('sell', 12, 6);
            $table->string('source', 10);
            $audit($table);
            $table->unique(['tenant_id', 'rate_date', 'currency']);
        });
        $checks('fin_fx_rates', ['source_check' => "source IN ('manual','SBS','SUNAT')"]);

        foreach (self::TABLES as $table) {
            Rls::tenantTable($table);
        }
        foreach (DB::table('tenants')->get(['id', 'settings']) as $tenant) {
            FinanceProvisioner::seedTenant($tenant->id, DB::connection(), json_decode($tenant->settings ?? '{}', true) ?: []);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table} CASCADE");
        }
    }
};
