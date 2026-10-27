<?php

namespace App\Console\Commands;

use App\Services\Treasury\TreasuryJobs;
use App\Support\OperatingTenants;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** `treasury:cash-flow-snapshot` JOB-14 (spec 12 §8, diario 06:00): precalcula el flujo proyectado. */
class TreasuryCashFlowSnapshotCommand extends Command
{
    protected $signature = 'treasury:cash-flow-snapshot {--tenant= : solo este tenant (Ejecutar ahora)}';

    protected $description = 'Tesorería: cachear el flujo proyectado';

    public function handle(TenantContext $context): int
    {
        foreach (OperatingTenants::ids($this->option('tenant'), null) as $tenantId) {
            $context->run($tenantId, fn () => $this->line(app(TreasuryJobs::class)->cashFlowSnapshot()));
        }

        return self::SUCCESS;
    }
}
