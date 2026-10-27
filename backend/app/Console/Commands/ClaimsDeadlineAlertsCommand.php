<?php

namespace App\Console\Commands;

use App\Services\Crm\ClaimBookService;
use App\Support\OperatingTenants;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** `claims:deadline-alerts` JOB-15 (spec 12 §8, diario 08:00): hojas del libro por vencer o vencidas al responsable y al Manager. */
class ClaimsDeadlineAlertsCommand extends Command
{
    protected $signature = 'claims:deadline-alerts {--tenant= : solo este tenant (Ejecutar ahora)}';

    protected $description = 'Libro de reclamaciones: alertas de plazo';

    public function handle(TenantContext $context): int
    {
        foreach (OperatingTenants::ids($this->option('tenant'), null) as $tenantId) {
            $context->run($tenantId, fn () => $this->line(app(ClaimBookService::class)->deadlineAlerts()));
        }

        return self::SUCCESS;
    }
}
