<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Helpers de migración para aislamiento multi-tenant (§3.11).
 *
 * La política falla cerrada: sin `app.tenant_id` en la sesión, current_setting()
 * devuelve NULL o '' y ninguna fila coincide.
 */
class Rls
{
    public const CONTEXT = "NULLIF(current_setting('app.tenant_id', true), '')::uuid";

    /** RLS estándar sobre tenant_id NOT NULL + GRANT al rol de la app. */
    public static function tenantTable(string $table, array $privileges = ['SELECT', 'INSERT', 'UPDATE']): void
    {
        $ctx = self::CONTEXT;

        self::enable($table);
        DB::statement("CREATE POLICY tenant_isolation ON {$table} USING (tenant_id = {$ctx}) WITH CHECK (tenant_id = {$ctx})");
        self::grantApp($table, $privileges);
    }

    /**
     * Tablas cuyo tenant_id puede ser NULL para filas de plataforma (idempotencia, outbox, auditoría):
     * con contexto se ven solo las del tenant; sin contexto, solo las de plataforma.
     */
    public static function nullableTenantTable(string $table, array $privileges = ['SELECT', 'INSERT', 'UPDATE']): void
    {
        $ctx = self::CONTEXT;

        self::enable($table);
        DB::statement("CREATE POLICY tenant_isolation ON {$table}
            USING (tenant_id = {$ctx} OR (tenant_id IS NULL AND {$ctx} IS NULL))
            WITH CHECK (tenant_id = {$ctx} OR (tenant_id IS NULL AND {$ctx} IS NULL))");
        self::grantApp($table, $privileges);
    }

    public static function enable(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
    }

    public static function grantApp(string $table, array $privileges): void
    {
        self::grant($table, $privileges, config('turismo.db_roles.app'));
    }

    public static function grantPlatform(string $table, array $privileges): void
    {
        self::grant($table, $privileges, config('turismo.db_roles.platform'));
    }

    /** Secuencias (id bigserial, códigos): USAGE para nextval(). */
    public static function grantSequence(string $sequence, string $role = 'app'): void
    {
        DB::statement(sprintf('GRANT USAGE, SELECT ON SEQUENCE %s TO %s', $sequence, config("turismo.db_roles.{$role}")));
    }

    private static function grant(string $table, array $privileges, string $role): void
    {
        DB::statement(sprintf('GRANT %s ON %s TO %s', implode(', ', $privileges), $table, $role));
    }
}
