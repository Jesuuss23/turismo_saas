<?php

namespace App\Jobs;

use App\Services\Integrations\WebhookService;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Spec 13 §8.4: un intento de entrega en la cola `integrations`. Los reintentos los agenda `next_retry_at`
 * y los toma `webhooks:deliver` (backoff 1 min, 5 min, 30 min, 2 h, 6 h, 24 h).
 */
class DeliverWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $tenantId, public string $deliveryId)
    {
        $this->onQueue('integrations');
    }

    public function handle(TenantContext $context, WebhookService $webhooks): void
    {
        $context->run($this->tenantId, function () use ($webhooks) {
            $delivery = DB::table('integration_webhook_deliveries')->where('id', $this->deliveryId)->whereIn('status', ['pending', 'retrying'])->first();
            if ($delivery) {
                $webhooks->send($delivery);
            }
        });
    }
}
