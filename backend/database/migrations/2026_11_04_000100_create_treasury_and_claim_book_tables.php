<?php

use App\Models\Sys\Holiday;
use App\Services\Treasury\TreasuryProvisioner;
use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'fin_treasury_accounts', 'fin_treasury_transfers', 'fin_cash_replenishments', 'fin_treasury_movements', 'fin_expense_receipts',
        'fin_cash_counts', 'fin_cash_sessions', 'fin_fx_revaluations', 'fin_treasury_settings', 'crm_claim_book_settings',
    ];

    // Spec 12 §3: tesorería (cuentas, movimientos, transferencias, caja chica, arqueos, reposiciones, revaluación) y
    // Libro de Reclamaciones (extiende crm_cases, parámetros del libro y feriados).
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

        // §3.1 cuentas: banco, caja chica por sede o pasarela (cuenta de tránsito). El saldo se deriva de los movimientos.
        Schema::create('fin_treasury_accounts', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->string('kind', 20);
            $table->string('bank', 40)->nullable();
            $table->string('name', 120);
            $table->string('account_number', 40)->nullable();
            $table->string('cci', 25)->nullable();
            $table->char('currency', 3);
            $table->foreignUuid('sede_id')->constrained('iam_sedes');
            $table->decimal('fund_amount', 14, 2)->nullable();
            $table->foreignUuid('custodian_id')->nullable()->constrained('iam_users');
            $table->uuid('settle_to_account_id')->nullable();
            // Vínculo con la cuenta bancaria de Finanzas (payouts de CxP, conciliación) y medios de cobro que ingresan aquí.
            $table->foreignUuid('bank_account_id')->nullable()->constrained('fin_bank_accounts');
            $table->jsonb('collect_methods')->default('[]');
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->decimal('opening_rate', 12, 6)->nullable();
            $table->date('opening_date');
            $table->string('status', 20)->default('active');
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'bank', 'account_number']);
            $table->index('sede_id');
        });
        Schema::table('fin_treasury_accounts', function (Blueprint $table) {
            $table->foreign('settle_to_account_id')->references('id')->on('fin_treasury_accounts');
        });
        $checks('fin_treasury_accounts', [
            'kind_check' => "kind IN ('bank','petty_cash','gateway')", 'currency_check' => "currency IN ('PEN','USD')",
            'status_check' => "status IN ('active','inactive')",
            'petty_check' => "kind <> 'petty_cash' OR (fund_amount IS NOT NULL AND fund_amount > 0 AND custodian_id IS NOT NULL)",
            'gateway_check' => "kind <> 'gateway' OR settle_to_account_id IS NOT NULL",
        ]);

        // §3.4 transferencias, depósitos de efectivo, reposiciones pagadas y liquidaciones de pasarela.
        Schema::create('fin_treasury_transfers', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->foreignUuid('from_account_id')->constrained('fin_treasury_accounts');
            $table->foreignUuid('to_account_id')->constrained('fin_treasury_accounts');
            $table->decimal('amount_from', 14, 2);
            $table->decimal('amount_to', 14, 2);
            $table->decimal('deal_rate', 12, 6)->nullable();
            $table->decimal('fee_amount', 14, 2)->nullable();
            $table->string('kind', 20);
            $table->date('transfer_date');
            $table->string('reference', 80);
            $table->string('status', 10)->default('posted');
            $audit($table);
            $table->unique(['tenant_id', 'code']);
            $table->index('from_account_id');
            $table->index('to_account_id');
        });
        $checks('fin_treasury_transfers', [
            'accounts_check' => 'from_account_id <> to_account_id', 'amount_check' => 'amount_from > 0 AND amount_to > 0',
            'kind_check' => "kind IN ('transfer','replenishment','gateway_settlement','cash_deposit')", 'status_check' => "status IN ('posted','voided')",
        ]);

        // §3.7 reposición del fondo fijo (los gastos rendidos apuntan aquí desde los movimientos).
        Schema::create('fin_cash_replenishments', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->foreignUuid('account_id')->constrained('fin_treasury_accounts');
            $table->decimal('amount', 14, 2);
            $table->string('status', 15)->default('requested');
            $table->foreignUuid('requested_by')->constrained('iam_users');
            $table->timestampTz('requested_at');
            $table->foreignUuid('approved_by')->nullable()->constrained('iam_users');
            $table->timestampTz('approved_at')->nullable();
            $table->foreignUuid('paid_by')->nullable()->constrained('iam_users');
            $table->timestampTz('paid_at')->nullable();
            $table->foreignUuid('paid_from_account_id')->nullable()->constrained('fin_treasury_accounts');
            $table->foreignUuid('transfer_id')->nullable()->constrained('fin_treasury_transfers');
            $table->text('reject_reason')->nullable();
            $table->text('note')->nullable();
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique(['tenant_id', 'code']);
            $table->index('account_id');
        });
        $checks('fin_cash_replenishments', [
            'status_check' => "status IN ('requested','approved','paid','rejected')", 'amount_check' => 'amount > 0',
            'segregation_check' => 'approved_by IS NULL OR approved_by <> requested_by',
        ]);
        // TES-BR-15: una sola reposición en curso por caja.
        DB::statement("CREATE UNIQUE INDEX fin_cash_replenishments_open_unique ON fin_cash_replenishments (tenant_id, account_id) WHERE status IN ('requested','approved')");
        Schema::create('fin_cash_replenishment_events', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('replenishment_id')->index()->constrained('fin_cash_replenishments');
            $table->string('status', 15);
            $table->text('message');
            $table->foreignUuid('user_id')->nullable()->constrained('iam_users');
            $table->timestampTz('created_at');
        });

        // §3.2 libro de movimientos.
        Schema::create('fin_treasury_movements', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->foreignUuid('account_id')->constrained('fin_treasury_accounts');
            $table->date('movement_date');
            $table->string('direction', 3);
            $table->string('type', 30);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->decimal('fx_rate', 12, 6)->nullable();
            $table->decimal('orig_amount', 14, 2)->nullable();
            $table->char('orig_currency', 3)->nullable();
            $table->decimal('orig_fx_rate', 12, 6)->nullable();
            $table->string('source_type', 30);
            $table->uuid('source_id')->nullable();
            $table->string('source_key', 60)->nullable();
            $table->string('source_code', 30)->nullable();
            $table->foreignUuid('transfer_id')->nullable()->constrained('fin_treasury_transfers');
            $table->foreignUuid('replenishment_id')->nullable()->constrained('fin_cash_replenishments');
            $table->foreignUuid('reservation_id')->nullable()->constrained('booking_reservations');
            $table->string('category', 60)->nullable();
            $table->string('description', 255);
            $table->string('reference', 80)->nullable();
            $table->string('counterparty', 160)->nullable();
            $table->foreignUuid('sede_id')->nullable()->constrained('iam_sedes');
            $table->foreignUuid('deposited_transfer_id')->nullable()->constrained('fin_treasury_transfers');
            $table->string('status', 20)->default('posted');
            $table->text('void_reason')->nullable();
            $table->foreignUuid('voided_by')->nullable()->constrained('iam_users');
            $table->timestampTz('voided_at')->nullable();
            $audit($table);
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'source_key']);
            $table->index(['tenant_id', 'account_id', 'movement_date']);
            $table->index(['tenant_id', 'movement_date']);
            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'replenishment_id']);
        });
        $checks('fin_treasury_movements', [
            'direction_check' => "direction IN ('in','out')",
            'type_check' => "type IN ('collection','collection_reversal','supplier_payment','transfer','petty_expense','replenishment','gateway_settlement','gateway_fee','count_adjustment','other_income','other_expense')",
            'amount_check' => 'amount > 0', 'currency_check' => "currency IN ('PEN','USD')",
            'source_check' => "source_type IN ('payment','payment_reversal','payout','payable','transfer','expense','replenishment','gateway','cash_count','manual')",
            'status_check' => "status IN ('posted','voided')",
            'void_check' => "status <> 'voided' OR (void_reason IS NOT NULL AND voided_by IS NOT NULL AND voided_at IS NOT NULL)",
        ]);

        // §3.3 comprobante del gasto de caja chica (TES-BR-12).
        Schema::create('fin_expense_receipts', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->foreignUuid('movement_id')->unique()->constrained('fin_treasury_movements');
            $table->string('receipt_type', 30);
            $table->string('series_number', 30)->nullable();
            $table->char('issuer_ruc', 11)->nullable();
            $table->string('supplier_name', 160);
            $table->string('file_path', 255)->nullable();
            $table->string('file_name', 160)->nullable();
            $audit($table);
        });
        $checks('fin_expense_receipts', [
            'type_check' => "receipt_type IN ('invoice','sales_receipt','ticket','fee_receipt','mobility_sheet','sworn_statement')",
            'ruc_check' => "issuer_ruc IS NULL OR issuer_ruc ~ '^\\d{11}$'",
            'series_check' => "receipt_type IN ('mobility_sheet','sworn_statement') OR series_number IS NOT NULL",
            'ruc_required_check' => "receipt_type NOT IN ('invoice','fee_receipt') OR issuer_ruc IS NOT NULL",
        ]);
        DB::statement('CREATE UNIQUE INDEX fin_expense_receipts_doc_unique ON fin_expense_receipts (tenant_id, issuer_ruc, series_number) WHERE issuer_ruc IS NOT NULL');

        // §3.6 acta de arqueo.
        Schema::create('fin_cash_counts', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->foreignUuid('account_id')->constrained('fin_treasury_accounts');
            $table->uuid('session_id')->nullable();
            $table->string('kind', 10);
            $table->timestampTz('counted_at');
            $table->jsonb('denominations');
            $table->decimal('counted_amount', 14, 2);
            $table->decimal('expected_amount', 14, 2);
            $table->decimal('difference', 14, 2);
            $table->string('result', 10);
            $table->text('note')->nullable();
            $table->foreignUuid('signed_by')->constrained('iam_users');
            $table->timestampTz('signed_at');
            $table->string('signed_ip', 45)->nullable();
            $table->foreignUuid('witness_id')->nullable()->constrained('iam_users');
            $table->string('status', 25);
            $table->foreignUuid('approved_by')->nullable()->constrained('iam_users');
            $table->timestampTz('approved_at')->nullable();
            $table->text('approve_note')->nullable();
            $table->foreignUuid('validated_by')->nullable()->constrained('iam_users');
            $table->timestampTz('validated_at')->nullable();
            $table->foreignUuid('adjustment_movement_id')->nullable()->constrained('fin_treasury_movements');
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique(['tenant_id', 'code']);
            $table->index('account_id');
        });
        $checks('fin_cash_counts', [
            'kind_check' => "kind IN ('surprise','closing')", 'result_check' => "result IN ('balanced','over','short')",
            'difference_check' => 'difference = counted_amount - expected_amount',
            'note_check' => 'difference = 0 OR note IS NOT NULL',
            'status_check' => "status IN ('signed','pending_approval','approved','validated')",
            'segregation_check' => 'approved_by IS NULL OR approved_by <> signed_by',
        ]);

        // §3.5 sesión diaria de caja chica.
        Schema::create('fin_cash_sessions', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->foreignUuid('account_id')->constrained('fin_treasury_accounts');
            $table->date('session_date');
            $table->timestampTz('opened_at');
            $table->foreignUuid('opened_by')->constrained('iam_users');
            $table->decimal('opening_amount', 14, 2);
            $table->timestampTz('closed_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('iam_users');
            $table->foreignUuid('closing_count_id')->nullable()->constrained('fin_cash_counts');
            $table->string('status', 10)->default('open');
            $audit($table);
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'account_id', 'session_date']);
        });
        $checks('fin_cash_sessions', [
            'status_check' => "status IN ('open','closed')",
            'closed_check' => "status <> 'closed' OR (closed_at IS NOT NULL AND closing_count_id IS NOT NULL)",
        ]);
        // TES-BR-06: una sola sesión abierta por caja.
        DB::statement("CREATE UNIQUE INDEX fin_cash_sessions_open_unique ON fin_cash_sessions (tenant_id, account_id) WHERE status = 'open'");
        Schema::table('fin_cash_counts', function (Blueprint $table) {
            $table->foreign('session_id')->references('id')->on('fin_cash_sessions');
        });

        // §3.8 diferencia cambiaria no realizada por cuenta USD y periodo (TES-BR-16).
        Schema::create('fin_fx_revaluations', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 20);
            $table->foreignUuid('account_id')->constrained('fin_treasury_accounts');
            $table->char('period', 7);
            $table->date('revaluation_date');
            $table->decimal('balance_usd', 14, 2);
            $table->decimal('book_rate', 12, 6);
            $table->decimal('close_rate', 12, 6);
            $table->decimal('book_value_pen', 14, 2);
            $table->decimal('diff_pen', 14, 2);
            $audit($table);
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'account_id', 'period']);
        });

        // §3.9 parámetros de tesorería (una fila por tenant).
        Schema::create('fin_treasury_settings', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->decimal('petty_tolerance', 14, 2)->default(10);
            $table->decimal('max_petty_expense', 14, 2)->default(500);
            $table->decimal('sworn_statement_max', 14, 2)->default(150);
            $table->smallInteger('replenish_threshold_pct')->default(40);
            $table->decimal('gateway_fee_pct', 5, 2)->default(4);
            $table->smallInteger('cash_deposit_hours')->default(24);
            $table->jsonb('recurring_outflows')->default('[]');
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique('tenant_id');
        });

        // §3.10 hoja del libro = caso de postventa con is_book (los casos existentes no cambian).
        DB::statement('ALTER TABLE crm_cases ALTER COLUMN client_id DROP NOT NULL');
        Schema::table('crm_cases', function (Blueprint $table) {
            $table->boolean('is_book')->default(false);
            $table->string('book_number', 25)->nullable();
            $table->foreignUuid('sede_id')->nullable()->constrained('iam_sedes');
            $table->string('channel', 30)->nullable();
            $table->string('book_status', 20)->nullable();
            $table->text('consumer')->nullable();
            $table->jsonb('good')->nullable();
            $table->text('detail')->nullable();
            $table->text('request')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->date('due_date')->nullable();
            $table->smallInteger('response_days')->nullable();
            $table->jsonb('response')->nullable();
            $table->text('close_note')->nullable();
            $table->timestampTz('ack_sent_at')->nullable();
            $table->foreignUuid('submitted_by')->nullable()->constrained('iam_users');
            $table->string('consumer_email', 160)->nullable();
            $table->unique(['tenant_id', 'book_number']);
            $table->index(['tenant_id', 'is_book', 'book_status']);
            $table->index(['tenant_id', 'sede_id']);
        });
        DB::statement("CREATE INDEX crm_cases_book_due_idx ON crm_cases (tenant_id, due_date) WHERE is_book AND book_status IN ('received','in_review')");
        DB::statement('ALTER TABLE crm_cases DROP CONSTRAINT crm_cases_origin_check');
        $checks('crm_cases', [
            'origin_check' => "origin IN ('manual','nps_auto','libro')",
            'client_check' => 'is_book OR client_id IS NOT NULL',
            'book_check' => 'NOT is_book OR (book_number IS NOT NULL AND sede_id IS NOT NULL AND consumer IS NOT NULL AND received_at IS NOT NULL AND due_date IS NOT NULL)',
            'book_type_check' => "NOT is_book OR type_code IN ('reclamo','queja')",
            'book_status_check' => "book_status IS NULL OR book_status IN ('received','in_review','answered','closed')",
            'book_channel_check' => "channel IS NULL OR channel IN ('web','customer_portal','passenger_portal','in_person')",
        ]);
        DB::statement('ALTER TABLE crm_case_events DROP CONSTRAINT crm_case_events_event_check');
        DB::statement("ALTER TABLE crm_case_events ADD CONSTRAINT crm_case_events_event_check CHECK (event IN ('registro','estado','seguimiento','respuesta','vinculo'))");

        // §3.11 parámetros del libro y feriados (nacionales con tenant_id NULL + locales del tenant).
        Schema::create('crm_claim_book_settings', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->smallInteger('response_days')->default(15);
            $table->smallInteger('alert_days')->default(3);
            $table->smallInteger('retention_years')->default(2);
            $table->foreignUuid('default_owner_id')->nullable()->constrained('iam_users');
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique('tenant_id');
        });
        $checks('crm_claim_book_settings', [
            'days_check' => 'response_days BETWEEN 1 AND 30', 'alert_check' => 'alert_days BETWEEN 0 AND 10', 'retention_check' => 'retention_years >= 2',
        ]);
        // LR-BR-01: correlativo por sede y año sin saltos (fila bloqueada con INSERT … ON CONFLICT DO UPDATE en la misma transacción del alta).
        Schema::create('crm_claim_book_counters', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('sede_id')->constrained('iam_sedes');
            $table->smallInteger('year');
            $table->integer('last_number')->default(0);
            $table->unique(['tenant_id', 'sede_id', 'year']);
        });
        Schema::create('sys_holidays', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants');
            $table->date('date');
            $table->string('name', 120);
            $table->timestampsTz();
            $table->index('date');
        });
        DB::statement('CREATE UNIQUE INDEX sys_holidays_unique ON sys_holidays (COALESCE(tenant_id, \'00000000-0000-0000-0000-000000000000\'::uuid), date)');
        Rls::enable('sys_holidays');
        $ctx = Rls::CONTEXT;
        DB::statement("CREATE POLICY tenant_isolation ON sys_holidays USING (tenant_id IS NULL OR tenant_id = {$ctx}) WITH CHECK (tenant_id = {$ctx})");
        Rls::grantApp('sys_holidays', ['SELECT', 'INSERT', 'DELETE']);
        Holiday::seedNational(DB::connection());

        foreach (self::TABLES as $table) {
            Rls::tenantTable($table);
        }
        Rls::tenantTable('fin_cash_replenishment_events', ['SELECT', 'INSERT']);
        Rls::tenantTable('crm_claim_book_counters', ['SELECT', 'INSERT', 'UPDATE']);

        foreach (DB::table('tenants')->get(['id']) as $tenant) {
            TreasuryProvisioner::seedTenant($tenant->id, DB::connection());
        }
    }

    public function down(): void
    {
        DB::table('sys_scheduled_jobs')->whereIn('code', TreasuryProvisioner::JOBS)->whereNull('report_key')->delete();
        DB::table('sys_sequences')->whereIn('doc_type', [...array_keys(TreasuryProvisioner::SEQUENCES), 'claim_book'])->delete();
        DB::table('crm_case_events')->whereIn('case_id', DB::table('crm_cases')->where('is_book', true)->select('id'))->delete();
        DB::table('crm_cases')->where('is_book', true)->delete();
        DB::statement('ALTER TABLE crm_case_events DROP CONSTRAINT crm_case_events_event_check');
        DB::statement("ALTER TABLE crm_case_events ADD CONSTRAINT crm_case_events_event_check CHECK (event IN ('registro','estado','seguimiento'))");
        foreach (['client_check', 'book_check', 'book_type_check', 'book_status_check', 'book_channel_check', 'origin_check'] as $c) {
            DB::statement("ALTER TABLE crm_cases DROP CONSTRAINT IF EXISTS crm_cases_{$c}");
        }
        DB::statement("ALTER TABLE crm_cases ADD CONSTRAINT crm_cases_origin_check CHECK (origin IN ('manual','nps_auto'))");
        DB::statement('DROP INDEX IF EXISTS crm_cases_book_due_idx');
        Schema::table('crm_cases', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'book_number']);
            $table->dropIndex(['tenant_id', 'is_book', 'book_status']);
            $table->dropIndex(['tenant_id', 'sede_id']);
            $table->dropConstrainedForeignId('sede_id');
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropColumn(['is_book', 'book_number', 'channel', 'book_status', 'consumer', 'good', 'detail', 'request', 'received_at', 'due_date',
                'response_days', 'response', 'close_note', 'ack_sent_at', 'consumer_email']);
        });
        DB::statement('ALTER TABLE crm_cases ALTER COLUMN client_id SET NOT NULL');
        foreach (['sys_holidays', 'crm_claim_book_counters', 'crm_claim_book_settings', 'fin_treasury_settings', 'fin_fx_revaluations', 'fin_cash_sessions', 'fin_cash_counts',
            'fin_expense_receipts', 'fin_treasury_movements', 'fin_cash_replenishment_events', 'fin_cash_replenishments', 'fin_treasury_transfers',
            'fin_treasury_accounts'] as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table} CASCADE");
        }
    }
};
