<?php

namespace App\Console\Commands;

use App\Services\Treasury\TreasuryJobs;
use App\Support\OperatingTenants;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** `treasury:open-session-check` JOB-13 (spec 12 §8, diario 10:00): cajas chicas sin aperturar (TES-BR-05). */
class TreasuryOpenSessionCheckCommand extends Command
{
    protected $signature = 'treasury:open-session-check {--tenant= : solo este tenant (Ejecutar ahora)}';

    protected $description = 'Tesorería: alertar cajas sin aperturar';

    public function handle(TenantContext $context): int
    {
        foreach (OperatingTenants::ids($this->option('tenant'), null) as $tenantId) {
            $context->run($tenantId, fn () => $this->line(app(TreasuryJobs::class)->openSessionCheck()));
        }

        return self::SUCCESS;
    }
}
