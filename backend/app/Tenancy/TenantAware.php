<?php

namespace App\Tenancy;

use App\Jobs\Middleware\SetTenantContext;

/**
 * Jobs con tenant (§3.11, §8.2): captura el tenant al despacharse y lo restablece antes de handle().
 * Un job sin tenant falla explícitamente (X-04).
 */
trait TenantAware
{
    public ?string $tenantId = null;

    public function captureTenant(): static
    {
        $this->tenantId ??= app(TenantContext::class)->id();

        return $this;
    }

    public function middleware(): array
    {
        return [new SetTenantContext];
    }
}
