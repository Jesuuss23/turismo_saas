<?php

namespace App\Console\Commands;

use App\Services\Quotes\QuoteWorkflow;
use App\Support\OperatingTenants;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;

/**
 * JOB-02 (spec 03 §8), diario 00:05: borrador/enviada/vista/negociación con validez pasada → vencida.
 * Idempotente; la fecha se evalúa en la zona horaria de cada tenant.
 */
class ExpireQuotes extends Command implements Isolatable
{
    protected $signature = 'quotes:expire {--tenant= : solo este tenant (Ejecutar ahora)}';

    protected $description = 'JOB-02: vence las cotizaciones con validez pasada';

    public function handle(TenantContext $context): int
    {
        $total = 0;
        foreach (OperatingTenants::ids($this->option('tenant'), 'JOB-02') as $tenantId) {
            $total += $context->run($tenantId, fn () => count(app(QuoteWorkflow::class)->expireDue()));
        }
        $this->info("{$total} cotización(es) vencida(s).");

        return self::SUCCESS;
    }
}
