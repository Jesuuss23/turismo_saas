<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // §3.6: append-only, particionada por mes.
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS audit_events_code_seq START 55000');

        DB::statement(<<<'SQL'
            CREATE TABLE audit_events (
                id uuid NOT NULL DEFAULT gen_random_uuid(),
                code bigint NOT NULL DEFAULT nextval('audit_events_code_seq'),
                tenant_id uuid NULL REFERENCES tenants(id),
                actor_type varchar(20) NOT NULL CHECK (actor_type IN ('user','platform_user','system','api_client')),
                actor_id uuid NULL,
                actor_role varchar(60) NULL,
                entity varchar(40) NOT NULL,
                entity_id varchar(80) NOT NULL,
                action varchar(40) NOT NULL,
                is_critical boolean NOT NULL DEFAULT false,
                before_value text NULL,
                after_value text NULL,
                diff jsonb NULL,
                ip inet NULL,
                user_agent varchar(250) NULL,
                correlation_id varchar(40) NOT NULL,
                evidence jsonb NULL,
                occurred_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (id, occurred_at)
            ) PARTITION BY RANGE (occurred_at)
        SQL);

        DB::statement('ALTER SEQUENCE audit_events_code_seq OWNED BY audit_events.code');

        // Los índices en la tabla padre se crean en cada partición.
        DB::statement('CREATE INDEX audit_events_tenant_time_idx ON audit_events (tenant_id, occurred_at DESC)');
        DB::statement('CREATE INDEX audit_events_entity_idx ON audit_events (tenant_id, entity, entity_id)');
        DB::statement('CREATE INDEX audit_events_actor_idx ON audit_events (tenant_id, actor_id)');
        DB::statement('CREATE INDEX audit_events_correlation_idx ON audit_events (correlation_id)');
        DB::statement('CREATE INDEX audit_events_action_idx ON audit_events (tenant_id, action)');

        // Crea la partición mensual de una fecha (la usa el comando audit:partitions).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_events_ensure_partition(p_month date) RETURNS void
            LANGUAGE plpgsql AS $$
            DECLARE
                v_from date := date_trunc('month', p_month)::date;
                v_to   date := (date_trunc('month', p_month) + interval '1 month')::date;
                v_name text := 'audit_events_' || to_char(v_from, 'YYYY_MM');
            BEGIN
                IF to_regclass(v_name) IS NULL THEN
                    EXECUTE format('CREATE TABLE %I PARTITION OF audit_events FOR VALUES FROM (%L) TO (%L)', v_name, v_from, v_to);
                END IF;
            END $$
        SQL);
        DB::statement('CREATE TABLE audit_events_default PARTITION OF audit_events DEFAULT');
        DB::statement("SELECT audit_events_ensure_partition((date_trunc('month', now()) + make_interval(months => m))::date) FROM generate_series(-1, 12) AS m");

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_events_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_events es append-only: % no permitido', TG_OP USING ERRCODE = 'insufficient_privilege';
            END $$
        SQL);
        DB::statement('CREATE TRIGGER audit_events_no_update_delete BEFORE UPDATE OR DELETE ON audit_events FOR EACH ROW EXECUTE FUNCTION audit_events_immutable()');

        // Eventos sin tenant (login fallido sin tenant, plataforma) solo visibles sin contexto de tenant.
        Rls::nullableTenantTable('audit_events', ['SELECT', 'INSERT']);
        $app = config('turismo.db_roles.app');
        DB::statement("REVOKE UPDATE, DELETE, TRUNCATE ON audit_events FROM {$app}");
        Rls::grantSequence('audit_events_code_seq');

        // La consola de plataforma lee toda la auditoría técnica (SEC-BR-06) y registra la suya.
        $platform = config('turismo.db_roles.platform');
        DB::statement("CREATE POLICY platform_read ON audit_events FOR SELECT TO {$platform} USING (true)");
        Rls::grantPlatform('audit_events', ['SELECT', 'INSERT']);
        Rls::grantSequence('audit_events_code_seq', 'platform');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS audit_events CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS audit_events_immutable()');
        DB::statement('DROP FUNCTION IF EXISTS audit_events_ensure_partition(date)');
        DB::statement('DROP SEQUENCE IF EXISTS audit_events_code_seq');
    }
};
