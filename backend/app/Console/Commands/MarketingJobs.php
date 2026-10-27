<?php

namespace App\Console\Commands;

use App\Models\Marketing\Campaign;
use App\Services\AuditLogger;
use App\Services\Crm\Targets\SalesTargetService;
use App\Services\Marketing\AttributionService;
use App\Services\Marketing\AutomationService;
use App\Services\Marketing\CampaignService;
use App\Support\OperatingTenants;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * `marketing:run {job}` (spec 14 §8): JOB-16 dispatch-scheduled (cada minuto), JOB-17 automations (cada 15 min), JOB-18 attribution (cada hora)
 * y JOB-19 targets-pending-close (diario 08:00).
 */
class MarketingJobs extends Command
{
    protected $signature = 'marketing:run {job : dispatch-scheduled|automations|attribution|targets-pending-close} {--tenant= : solo este tenant (Ejecutar ahora)}';

    protected $description = 'Marketing y metas: campañas programadas, automatizaciones, atribución y metas por cerrar';

    public function handle(TenantContext $context): int
    {
        $job = (string) $this->argument('job');
        if (! in_array($job, ['dispatch-scheduled', 'automations', 'attribution', 'targets-pending-close'], true)) {
            $this->error("Job desconocido: {$job}");

            return self::INVALID;
        }
        foreach (OperatingTenants::ids($this->option('tenant'), null) as $tenantId) {
            $context->run($tenantId, fn () => $this->line(match ($job) {
                'dispatch-scheduled' => $this->dispatchScheduled(),
                'automations' => json_encode(app(AutomationService::class)->runActive()),
                'attribution' => json_encode(app(AttributionService::class)->recompute()),
                default => app(SalesTargetService::class)->pendingClose(),
            }));
        }

        return self::SUCCESS;
    }

    /** DispatchScheduledCampaigns: programadas con `scheduled_at ≤ now` → enviando (re-valida MKT-BR-03/04/07/09). */
    private function dispatchScheduled(): string
    {
        $started = 0;
        foreach (Campaign::where('status', 'programada')->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->get() as $c) {
            try {
                app(CampaignService::class)->send($c, null);
                $started++;
            } catch (Throwable $e) {
                app(AuditLogger::class)->log('Campaña', $c->code, 'envio_fallido', 'Programada', $e->getMessage(), system: true);
            }
        }

        return "{$started} campaña(s) iniciada(s)";
    }
}
