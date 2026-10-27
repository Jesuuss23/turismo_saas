<?php

namespace App\Console\Commands;

use App\Services\Collab\ApprovalSla;
use App\Support\OperatingTenants;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;

/** `approvals:sla-check` (spec 10 §8, cada 5 min): advertencia al 75 % del SLA y vencimiento con escalamiento al Manager. */
class ApprovalsSlaCheck extends Command implements Isolatable
{
    protected $signature = 'approvals:sla-check {--tenant= : solo este tenant (Ejecutar ahora)}';

    protected $description = 'Aprobaciones: advertencias y vencimientos de SLA';

    public function handle(TenantContext $context): int
    {
        foreach (OperatingTenants::ids($this->option('tenant'), null) as $tenantId) {
            $context->run($tenantId, function () {
                $r = app(ApprovalSla::class)->check();
                $this->line("{$r['warnings']} advertencia(s), {$r['overdue']} vencida(s)");
            });
        }

        return self::SUCCESS;
    }
}
