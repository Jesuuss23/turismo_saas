<?php

namespace App\Console\Commands;

use App\Models\Experience\ReviewSource;
use App\Services\Experience\CurrencyService;
use App\Services\Experience\PaxMessageService;
use App\Services\Experience\ReviewService;
use App\Services\Experience\WidgetService;
use App\Support\OperatingTenants;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * `experience:run {job}` (spec 17 §8): SchedulePaxMessages, DispatchDuePaxMessages, RefreshFxRates (`fx-update-daily`),
 * SyncExternalReviews, PublishReviewReply, VerifyWidgetDomain y ExpireWidgetHandoffs, por tenant operativo.
 */
class ExperienceJobs extends Command
{
    private const JOBS = ['schedule-messages', 'dispatch-messages', 'fx-update-daily', 'sync-reviews', 'publish-replies', 'verify-domains', 'expire-handoffs'];

    protected $signature = 'experience:run {job : schedule-messages|dispatch-messages|fx-update-daily|sync-reviews|publish-replies|verify-domains|expire-handoffs} {--tenant= : solo este tenant}';

    protected $description = 'Mensajes al pasajero, tipos de cambio, reseñas externas y widget (spec 17)';

    public function handle(TenantContext $context): int
    {
        $job = (string) $this->argument('job');
        if (! in_array($job, self::JOBS, true)) {
            $this->error("Job desconocido: {$job}");

            return self::INVALID;
        }
        foreach (OperatingTenants::ids($this->option('tenant'), null) as $tenantId) {
            $context->run($tenantId, function () use ($job, $tenantId) {
                try {
                    $this->line($tenantId.' '.json_encode(match ($job) {
                        'schedule-messages' => app(PaxMessageService::class)->schedule(),
                        'dispatch-messages' => app(PaxMessageService::class)->dispatchDue(),
                        'fx-update-daily' => app(CurrencyService::class)->refresh(),
                        'sync-reviews' => ReviewSource::where('status', 'conectado')->get()->map(fn ($s) => app(ReviewService::class)->sync($s)['imported'])->all(),
                        'publish-replies' => app(ReviewService::class)->publishPending(),
                        'verify-domains' => app(WidgetService::class)->verifyPending(),
                        default => app(WidgetService::class)->expire(),
                    }));
                } catch (Throwable $e) {
                    // FX-04: si el proveedor falla se conserva el último valor; el error queda en el proveedor y en el log.
                    $this->warn("{$tenantId} {$job}: {$e->getMessage()}");
                }
            });
        }

        return self::SUCCESS;
    }
}
