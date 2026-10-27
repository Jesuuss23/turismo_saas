<?php

namespace App\Console\Commands;

use App\Services\Treasury\TreasuryJobs;
use App\Support\OperatingTenants;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** `treasury:cash-deposit-alerts` JOB-12 (spec 12 §8, cada hora): cobros en efectivo sin depositar (TES-BR-09). */
class TreasuryCashDepositAlertsCommand extends Command
{
    protected $signature = 'treasury:cash-deposit-alerts {--tenant= : solo este tenant (Ejecutar ahora)}';

    protected $description = 'Tesorería: alertar cobros en efectivo sin depositar';

    public function handle(TenantContext $context): int
    {
        foreach (OperatingTenants::ids($this->option('tenant'), null) as $tenantId) {
            $context->run($tenantId, fn () => $this->line(app(TreasuryJobs::class)->cashDepositAlerts()));
        }

        return self::SUCCESS;
    }
}
