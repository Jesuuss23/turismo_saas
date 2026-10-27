<?php

namespace App\Console\Commands;

use App\Services\Catalog\AvailabilityService;
use App\Support\OperatingTenants;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;

/**
 * JOB-01 (spec 02 §8.2), cada 5 minutos: holds `hold` vencidos → `expirada` (CAT-BR-25).
 * Idempotente; `Isolatable` evita dos ejecuciones simultáneas (equivale a ShouldBeUnique).
 */
class ExpireAvailabilityHolds extends Command implements Isolatable
{
    protected $signature = 'catalog:expire-holds {--tenant= : solo este tenant (Ejecutar ahora)}';

    protected $description = 'JOB-01: expira los holds de disponibilidad vencidos';

    public function handle(TenantContext $context): int
    {
        $total = 0;
        foreach (OperatingTenants::ids($this->option('tenant'), 'JOB-01') as $tenantId) {
            $total += $context->run($tenantId, fn () => count(app(AvailabilityService::class)->expireHolds()));
        }
        $this->info("{$total} hold(s) expirado(s).");

        return self::SUCCESS;
    }
}
