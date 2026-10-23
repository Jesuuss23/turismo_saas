<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tenant de la solicitud o del job. Única fuente: el token (SEC-BR-02).
 *
 * Fija `app.tenant_id` en la sesión de PostgreSQL (RLS) y el team de spatie. Se usa
 * set_config a nivel de sesión porque las solicitudes no corren dentro de una transacción;
 * ResetTenantContext lo limpia al inicio y al final de cada solicitud (seguro con Octane).
 * Con PgBouncer en modo transacción habría que pasar a set_config(..., true) por transacción.
 */
class TenantContext
{
    private ?string $tenantId = null;

    private ?Tenant $tenant = null;

    public function set(Tenant|string $tenant): void
    {
        $this->tenant = $tenant instanceof Tenant ? $tenant : null;
        $this->tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;

        $this->apply();
    }

    public function clear(): void
    {
        $this->tenantId = null;
        $this->tenant = null;

        $this->apply();
    }

    public function id(): ?string
    {
        return $this->tenantId;
    }

    public function has(): bool
    {
        return $this->tenantId !== null;
    }

    public function tenant(): ?Tenant
    {
        if ($this->tenant === null && $this->tenantId !== null) {
            $this->tenant = Tenant::find($this->tenantId);
        }

        return $this->tenant;
    }

    /** Ejecuta $callback con otro tenant y restaura el anterior (seeders, jobs, comandos). */
    public function run(Tenant|string $tenant, callable $callback): mixed
    {
        $previous = $this->tenantId;
        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $previous === null ? $this->clear() : $this->set($previous);
        }
    }

    private function apply(): void
    {
        DB::connection()->select("SELECT set_config('app.tenant_id', ?, false)", [$this->tenantId ?? '']);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($this->tenantId);
        // Caché de spatie por tenant (§3.5): los roles de cada tenant son distintos.
        $registrar->cacheKey = config('permission.cache.key').($this->tenantId ? ".tenant.{$this->tenantId}" : '');
        $registrar->clearPermissionsCollection();
    }
}
