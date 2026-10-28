<?php

namespace App\Console\Commands;

use App\Services\Catalog\Booking\BookingHoldsService;
use App\Support\OperatingTenants;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * `booking:run {job}` (spec 16 §8.2): `expire-holds` (ExpireCheckoutHolds + ReleasePayLaterHolds, cada 5 min) y
 * `min-group` (EvaluateMinimumGroup, diario).
 */
class BookingEngineJobs extends Command
{
    protected $signature = 'booking:run {job : expire-holds|min-group} {--tenant= : solo este tenant (Ejecutar ahora)}';

    protected $description = 'Motor de reservas: holds vencidos de reservas online y grupo mínimo';

    public function handle(TenantContext $context): int
    {
        $job = (string) $this->argument('job');
        if (! in_array($job, ['expire-holds', 'min-group'], true)) {
            $this->error("Job desconocido: {$job}");

            return self::INVALID;
        }
        foreach (OperatingTenants::ids($this->option('tenant'), null) as $tenantId) {
            $context->run($tenantId, fn () => $this->line(json_encode($job === 'expire-holds'
                ? app(BookingHoldsService::class)->expire()
                : app(BookingHoldsService::class)->evaluateMinimumGroup())));
        }

        return self::SUCCESS;
    }
}
