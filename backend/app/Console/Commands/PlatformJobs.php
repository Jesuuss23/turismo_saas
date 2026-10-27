<?php

namespace App\Console\Commands;

use App\Services\Integrations\ApiKeyService;
use App\Services\Integrations\WebhookService;
use App\Services\Platform\DunningService;
use App\Services\Platform\MetricsService;
use App\Services\Platform\PlatformCatalog;
use App\Services\Platform\PlatformClock;
use App\Services\Platform\PlatformEvents;
use App\Services\Platform\RenewalService;
use App\Services\Platform\TrialService;
use App\Support\OperatingTenants;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Jobs programados de la plataforma SaaS (spec 13 §8.2). `platform:run {job}`; los alias de la spec
 * (`billing:renewals`, `billing:dunning`, …) se registran en routes/console.php y delegan aquí.
 */
class PlatformJobs extends Command
{
    public const JOBS = ['renewals', 'dunning', 'trials', 'expire-cards', 'usage-consolidate', 'metrics-snapshot', 'api-keys-expire', 'webhooks-deliver', 'webhooks-purge'];

    protected $signature = 'platform:run {job : '.'renewals|dunning|trials|expire-cards|usage-consolidate|metrics-snapshot|api-keys-expire|webhooks-deliver|webhooks-purge}';

    protected $description = 'Plataforma SaaS: renovaciones, cobranza, trials, medición, métricas, API keys y webhooks';

    public function handle(TenantContext $context): int
    {
        $job = (string) $this->argument('job');
        $result = match ($job) {
            'renewals' => app(RenewalService::class)->run(),
            'dunning' => app(DunningService::class)->run(),
            'trials' => app(TrialService::class)->run(),
            'expire-cards' => $this->expireCards(),
            'usage-consolidate' => app(MetricsService::class)->consolidate(),
            'metrics-snapshot' => app(MetricsService::class)->snapshot(PlatformClock::today()->subDay()),
            'api-keys-expire' => $this->perTenant($context, fn () => app(ApiKeyService::class)->expire()),
            'webhooks-deliver' => $this->perTenant($context, fn () => $this->deliverDue()),
            'webhooks-purge' => $this->perTenant($context, fn () => ['purged' => DB::table('integration_webhook_deliveries')->where('created_at', '<', now()->subDays(30))->delete()]),
            default => null,
        };
        if ($result === null) {
            $this->error('Job desconocido: '.$job);

            return self::INVALID;
        }
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /** Entregas pendientes o con reintento vencido (backoff de §8.4). */
    private function deliverDue(): array
    {
        $service = app(WebhookService::class);
        $due = DB::table('integration_webhook_deliveries')->where(fn ($q) => $q->where('status', 'pending')
            ->orWhere(fn ($q) => $q->where('status', 'retrying')->where('next_retry_at', '<=', now())))->orderBy('created_at')->limit(200)->get();
        $sent = 0;
        foreach ($due as $delivery) {
            $service->send($delivery);
            $sent++;
        }

        return ['sent' => $sent];
    }

    /** `billing:expire-cards` (mensual): avisa las tarjetas que vencen en 30 días y marca las vencidas. */
    private function expireCards(): array
    {
        $db = DB::connection(PlatformCatalog::CONNECTION);
        $today = PlatformClock::today();
        $soon = $today->copy()->addDays(30);
        $notified = 0;
        foreach ($db->table('payment_methods')->where('status', 'active')->get() as $m) {
            $expiry = $today->copy()->setDate($m->exp_year, $m->exp_month, 1)->endOfMonth();
            if ($expiry->lt($today)) {
                $db->table('payment_methods')->where('id', $m->id)->update(['status' => 'expired']);
            } elseif ($expiry->lte($soon)) {
                app(PlatformEvents::class)->notifyTenant($m->tenant_id, 'suscripcion.metodo_pago', 'Su tarjeta vence pronto',
                    "La tarjeta {$m->brand} •••• {$m->last4} vence el {$expiry->format('m/Y')}. Actualícela en Suscripción › Pago.");
                $notified++;
            }
        }

        return ['notified' => $notified];
    }

    private function perTenant(TenantContext $context, callable $fn): array
    {
        $out = [];
        foreach (OperatingTenants::ids() as $tenantId) {
            $out[$tenantId] = $context->run($tenantId, $fn);
        }

        return $out;
    }
}
