<?php

namespace App\Console\Commands;

use App\Services\Inventory\ReleaseAllotments;
use App\Support\OperatingTenants;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;

/** `allotments:release` (JOB-10, spec 11 §8, diario 00:15): release automático, cargos por no-uso y vencimiento de contratos. */
class ReleaseAllotmentsCommand extends Command implements Isolatable
{
    protected $signature = 'allotments:release {--tenant= : solo este tenant (Ejecutar ahora)}';

    protected $description = 'Cupos: liberar fechas en release, registrar penalidades y vencer contratos';

    public function handle(TenantContext $context): int
    {
        foreach (OperatingTenants::ids($this->option('tenant'), null) as $tenantId) {
            $context->run($tenantId, fn () => $this->line(app(ReleaseAllotments::class)->run()['summary']));
        }

        return self::SUCCESS;
    }
}
