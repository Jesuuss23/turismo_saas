<?php

namespace App\Console\Commands;

use App\Services\Collab\InboxService;
use App\Support\OperatingTenants;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;

/** `inbox:first-response-sla` (spec 10 §8, cada minuto): conversaciones sin 1.ª respuesta por vencer o vencidas (INB-BR-01). */
class InboxFirstResponseSla extends Command implements Isolatable
{
    protected $signature = 'inbox:first-response-sla {--tenant= : solo este tenant (Ejecutar ahora)}';

    protected $description = 'Bandeja: alertas de SLA de primera respuesta';

    public function handle(TenantContext $context): int
    {
        foreach (OperatingTenants::ids($this->option('tenant'), null) as $tenantId) {
            $context->run($tenantId, fn () => $this->line(app(InboxService::class)->firstResponseSla().' alerta(s)'));
        }

        return self::SUCCESS;
    }
}
