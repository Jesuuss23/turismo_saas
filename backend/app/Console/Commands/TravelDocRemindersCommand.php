<?php

namespace App\Console\Commands;

use App\Services\Ops\TravelDocs;
use App\Support\OperatingTenants;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;

/** `travel-docs:remind` (JOB-11, spec 11 §8, diario 08:00): recordatorios de documentos a las reservas incompletas. */
class TravelDocRemindersCommand extends Command implements Isolatable
{
    protected $signature = 'travel-docs:remind {--tenant= : solo este tenant (Ejecutar ahora)}';

    protected $description = 'Documentos de viaje: recordatorios automáticos al cliente';

    public function handle(TenantContext $context): int
    {
        foreach (OperatingTenants::ids($this->option('tenant'), null) as $tenantId) {
            $context->run($tenantId, fn () => $this->line(app(TravelDocs::class)->remind()['summary']));
        }

        return self::SUCCESS;
    }
}
