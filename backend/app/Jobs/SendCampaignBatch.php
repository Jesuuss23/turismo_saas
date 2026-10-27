<?php

namespace App\Jobs;

use App\Models\Marketing\Campaign;
use App\Services\Marketing\CampaignService;
use App\Tenancy\TenantAware;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Spec 14 §8: lote de 200 destinatarios por minuto (rate limit ESP / tier de Meta); se re-encola mientras queden pendientes. */
class SendCampaignBatch implements ShouldBeUnique, ShouldQueue
{
    use Queueable, TenantAware;

    public int $tries = 4;

    public function __construct(public string $campaignId)
    {
        $this->onQueue('notifications');
        $this->captureTenant();
    }

    public function uniqueId(): string
    {
        return $this->campaignId;
    }

    /** @return list<int> reintentos 1/5/15 min */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(CampaignService $campaigns): void
    {
        $campaign = Campaign::find($this->campaignId);
        if (! $campaign) {
            return;
        }
        $result = $campaigns->processBatch($campaign);
        if ($result['pending'] > 0) {
            dispatch(new self($this->campaignId))->delay(now()->addMinute());
        }
    }
}
