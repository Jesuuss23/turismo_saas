<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'quote_quotes', 'quote_versions', 'quote_items', 'quote_internal_approvals',
        'quote_sends', 'quote_access_tokens', 'quote_views', 'quote_negotiations',
    ];

    // Spec 03 §3: cotizaciones, versiones congeladas, ítems, aprobaciones internas, envíos, enlaces, vistas y negociación.
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

        Schema::create('quote_quotes', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->string('code', 30);
            $table->foreignUuid('client_id')->constrained('crm_clients');
            $table->foreignUuid('opportunity_id')->nullable()->constrained('crm_opportunities');
            $table->foreignUuid('owner_id')->constrained('iam_users');
            $table->string('title', 200);
            $table->string('status', 30)->default('borrador');
            $table->smallInteger('current_version_no')->default(1);
            $table->uuid('current_version_id')->nullable();
            $table->date('travel_from');
            $table->date('travel_to');
            $table->date('valid_until');
            $table->char('currency', 3);
            $table->decimal('fx_rate', 12, 6);
            $table->smallInteger('pax_adt')->default(0);
            $table->smallInteger('pax_chd')->default(0);
            $table->smallInteger('pax_inf')->default(0);
            $table->timestampTz('viewed_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->string('approved_by_name', 150)->nullable();
            $table->string('approval_channel', 60)->nullable();
            $table->string('approval_ref', 200)->nullable();
            $table->uuid('approval_recorded_by')->nullable();
            $table->ipAddress('approval_ip')->nullable();
            $table->string('approval_hash', 80)->nullable();
            $table->smallInteger('approval_version_no')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->text('rejected_reason')->nullable();
            // FK a booking_reservations llega con Reservas (spec 04).
            $table->uuid('reservation_id')->nullable();
            $table->timestampTz('converted_at')->nullable();
            $table->uuid('duplicated_from_id')->nullable();
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'valid_until']);
            $table->index(['tenant_id', 'client_id']);
            $table->index(['tenant_id', 'opportunity_id']);
            $table->index(['tenant_id', 'created_at']);
        });
        Schema::table('quote_quotes', fn (Blueprint $table) => $table->foreign('duplicated_from_id')->references('id')->on('quote_quotes'));
        $checks('quote_quotes', [
            'status_check' => "status IN ('borrador','enviada','vista','negociacion','aprobada','rechazada','vencida','convertida','cancelada')",
            'travel_check' => 'travel_to >= travel_from',
            'currency_check' => "currency IN ('PEN','USD')",
            'pax_check' => 'pax_adt >= 0 AND pax_chd >= 0 AND pax_inf >= 0 AND pax_adt + pax_chd + pax_inf > 0',
        ]);
        DB::statement('CREATE UNIQUE INDEX quote_quotes_reservation_unique ON quote_quotes (tenant_id, reservation_id) WHERE reservation_id IS NOT NULL');

        Schema::create('quote_versions', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->foreignUuid('quote_id')->constrained('quote_quotes')->restrictOnDelete();
            $table->smallInteger('version_no');
            $table->string('status', 30)->default('borrador');
            $table->date('issued_on');
            $table->foreignUuid('author_id')->constrained('iam_users');
            $table->decimal('discount_pct', 5, 2)->default(0);
            $table->decimal('tax_pct', 5, 2);
            $table->decimal('markup_pct', 9, 2)->default(0);
            $table->text('notes')->nullable();
            $table->text('conditions')->nullable();
            $table->string('snapshot_label', 300)->default('');
            $table->jsonb('catalog_snapshot')->default('[]');
            $table->jsonb('policies_snapshot')->default('{}');
            $table->decimal('fx_rate', 12, 6);
            foreach (['cost_total', 'sale_total', 'discount_total', 'net_total', 'tax_total', 'grand_total', 'profit_total'] as $total) {
                $table->decimal($total, 14, 2)->default(0);
            }
            $table->decimal('margin_pct', 7, 2)->default(0);
            $table->uuid('internal_approval_id')->nullable();
            $table->timestampTz('frozen_at')->nullable();
            $table->string('content_hash', 80)->nullable();
            $table->uuid('pdf_document_id')->nullable();
            $table->integer('lock_version')->default(0);
            $audit($table);
            $table->unique(['tenant_id', 'quote_id', 'version_no']);
        });
        $checks('quote_versions', [
            'status_check' => "status IN ('borrador','enviada','aprobada','rechazada','reemplazada','vencida')",
            'discount_check' => 'discount_pct BETWEEN 0 AND 50',
            'tax_check' => 'tax_pct BETWEEN 0 AND 18',
        ]);
        DB::statement('ALTER TABLE quote_quotes ADD CONSTRAINT quote_quotes_current_version_fk FOREIGN KEY (current_version_id) REFERENCES quote_versions(id) DEFERRABLE INITIALLY DEFERRED');

        Schema::create('quote_items', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->foreignUuid('quote_version_id')->index()->constrained('quote_versions')->cascadeOnDelete();
            $table->smallInteger('line_no');
            $table->foreignUuid('product_id')->constrained('catalog_products');
            $table->string('product_version', 10)->default('');
            $table->foreignUuid('rate_id')->nullable()->constrained('catalog_rates');
            $table->string('segment', 15);
            $table->date('service_date');
            $table->integer('qty');
            $table->integer('pax');
            $table->decimal('unit_cost', 14, 2);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('cost_total', 14, 2)->storedAs('unit_cost * qty * pax');
            $table->decimal('sale_total', 14, 2)->storedAs('unit_price * qty * pax');
            $table->foreignUuid('season_id')->nullable()->constrained('catalog_seasons');
            $table->decimal('season_factor', 6, 3)->nullable();
            $table->string('price_source', 10);
            $table->jsonb('rate_snapshot')->nullable();
            $audit($table);
        });
        $checks('quote_items', [
            'segment_check' => "segment IN ('Adulto','Niño','Infante','Grupo','Pasajero')",
            'qty_check' => 'qty > 0',
            'pax_check' => 'pax > 0',
            'cost_check' => 'unit_cost >= 0',
            'price_check' => 'unit_price > 0',
            'source_check' => "price_source IN ('rate','manual')",
        ]);

        Schema::create('quote_internal_approvals', function (Blueprint $table) use ($base, $audit) {
            $base($table);
            $table->foreignUuid('quote_version_id')->constrained('quote_versions');
            $table->jsonb('reasons');
            $table->boolean('over_cap')->default(false);
            $table->uuid('requested_by')->nullable();
            $table->timestampTz('requested_at')->nullable();
            $table->text('request_note')->nullable();
            $table->string('status', 20);
            $table->uuid('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->boolean('is_override')->default(false);
            $audit($table);
            $table->index(['tenant_id', 'status']);
        });
        $checks('quote_internal_approvals', ['status_check' => "status IN ('pendiente','aprobada','rechazada','anulada')"]);
        // QTE-BR-15: una sola solicitud pendiente por versión.
        DB::statement("CREATE UNIQUE INDEX quote_internal_approvals_pending_unique ON quote_internal_approvals (tenant_id, quote_version_id) WHERE status = 'pendiente'");
        Schema::table('quote_versions', fn (Blueprint $table) => $table->foreign('internal_approval_id')->references('id')->on('quote_internal_approvals'));

        Schema::create('quote_sends', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('quote_id')->constrained('quote_quotes');
            $table->smallInteger('version_no');
            $table->string('channel', 15);
            $table->string('recipient', 200);
            $table->text('message')->nullable();
            $table->foreignUuid('sent_by')->constrained('iam_users');
            $table->timestampTz('sent_at');
            $table->foreignUuid('notification_id')->nullable()->constrained('notif_messages');
            $table->index(['tenant_id', 'quote_id']);
        });
        $checks('quote_sends', ['channel_check' => "channel IN ('Email','WhatsApp','Portal')"]);

        Schema::create('quote_access_tokens', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('quote_id')->index()->constrained('quote_quotes');
            $table->string('token_hash', 80)->unique();
            // §12: el token también se guarda cifrado con la APP_KEY para poder mostrar el enlace vigente y reenviarlo.
            $table->text('token_cipher');
            $table->uuid('created_by');
            $table->timestampTz('created_at');
            $table->date('expires_at');
            $table->timestampTz('revoked_at')->nullable();
        });

        Schema::create('quote_views', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('quote_id')->index()->constrained('quote_quotes');
            $table->smallInteger('version_no');
            $table->timestampTz('viewed_at');
            $table->string('source', 15);
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->uuid('recorded_by')->nullable();
        });
        $checks('quote_views', ['source_check' => "source IN ('portal','manual')"]);

        Schema::create('quote_negotiations', function (Blueprint $table) use ($base) {
            $base($table);
            $table->foreignUuid('quote_id')->index()->constrained('quote_quotes');
            $table->string('channel', 15);
            $table->text('note');
            $table->smallInteger('created_version_no')->nullable();
            $table->foreignUuid('created_by')->constrained('iam_users');
            $table->timestampTz('created_at');
        });
        $checks('quote_negotiations', ['channel_check' => "channel IN ('Llamada','Reunión','Email','WhatsApp')"]);

        // QTE-BR-19 / RT-7: una versión congelada (enviada) no admite cambios de montos ni de ítems; solo de estado.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION quote_versions_frozen() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.frozen_at IS NOT NULL AND (
                    NEW.discount_pct IS DISTINCT FROM OLD.discount_pct OR NEW.tax_pct IS DISTINCT FROM OLD.tax_pct
                    OR NEW.markup_pct IS DISTINCT FROM OLD.markup_pct OR NEW.notes IS DISTINCT FROM OLD.notes
                    OR NEW.conditions IS DISTINCT FROM OLD.conditions OR NEW.catalog_snapshot IS DISTINCT FROM OLD.catalog_snapshot
                    OR NEW.policies_snapshot IS DISTINCT FROM OLD.policies_snapshot OR NEW.fx_rate IS DISTINCT FROM OLD.fx_rate
                    OR NEW.cost_total IS DISTINCT FROM OLD.cost_total OR NEW.sale_total IS DISTINCT FROM OLD.sale_total
                    OR NEW.discount_total IS DISTINCT FROM OLD.discount_total OR NEW.net_total IS DISTINCT FROM OLD.net_total
                    OR NEW.tax_total IS DISTINCT FROM OLD.tax_total OR NEW.grand_total IS DISTINCT FROM OLD.grand_total
                    OR NEW.profit_total IS DISTINCT FROM OLD.profit_total OR NEW.margin_pct IS DISTINCT FROM OLD.margin_pct
                    OR NEW.frozen_at IS DISTINCT FROM OLD.frozen_at OR NEW.content_hash IS DISTINCT FROM OLD.content_hash
                    OR NEW.version_no IS DISTINCT FROM OLD.version_no OR NEW.quote_id IS DISTINCT FROM OLD.quote_id
                ) THEN
                    RAISE EXCEPTION 'La versión de la cotización está congelada (QTE-BR-19)' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER quote_versions_frozen BEFORE UPDATE ON quote_versions FOR EACH ROW EXECUTE FUNCTION quote_versions_frozen()');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION quote_items_frozen() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                version_id uuid := CASE WHEN TG_OP = 'DELETE' THEN OLD.quote_version_id ELSE NEW.quote_version_id END;
            BEGIN
                IF EXISTS (SELECT 1 FROM quote_versions WHERE id = version_id AND frozen_at IS NOT NULL)
                   OR (TG_OP = 'UPDATE' AND EXISTS (SELECT 1 FROM quote_versions WHERE id = OLD.quote_version_id AND frozen_at IS NOT NULL)) THEN
                    RAISE EXCEPTION 'Los ítems de una versión congelada no se modifican (QTE-BR-19)' USING ERRCODE = 'check_violation';
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER quote_items_frozen BEFORE INSERT OR UPDATE OR DELETE ON quote_items FOR EACH ROW EXECUTE FUNCTION quote_items_frozen()');

        Rls::tenantTable('quote_quotes');
        Rls::tenantTable('quote_versions');
        Rls::tenantTable('quote_items', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
        foreach (['quote_internal_approvals', 'quote_sends', 'quote_access_tokens', 'quote_views', 'quote_negotiations'] as $table) {
            Rls::tenantTable($table);
        }

        // §11-2 / QTE-BR-25: permiso explícito de reasignación para Tenant Admin y Manager en los tenants existentes.
        $permission = DB::table('permissions')->where('name', 'quotes.reassign')->where('guard_name', 'api')->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => 'quotes.reassign', 'guard_name' => 'api', 'module' => 'Cotizaciones',
                'description' => 'Reasignar el asesor de una cotización',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        foreach (DB::table('roles')->whereIn('name', ['tenant_admin', 'manager'])->pluck('id') as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permission, 'role_id' => $roleId]);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE quote_quotes DROP CONSTRAINT IF EXISTS quote_quotes_current_version_fk');
        Schema::table('quote_versions', fn (Blueprint $table) => $table->dropForeign(['internal_approval_id']));
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS quote_versions_frozen()');
        DB::statement('DROP FUNCTION IF EXISTS quote_items_frozen()');
    }
};
