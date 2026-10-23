<?php

namespace App\Listeners\Crm;

use App\Events\Quotes\QuoteConverted;
use App\Events\Quotes\QuoteCreated;
use App\Events\Quotes\QuoteNegotiationRecorded;
use App\Services\Crm\OpportunityService;

/**
 * CRM-BR-27: la oportunidad avanza sola según su cotización. Corre síncrono dentro de la
 * transacción de Cotizaciones (mismo tenant) para que el pipeline no quede desfasado.
 */
class SyncOpportunityFromQuote
{
    public function __construct(private readonly OpportunityService $opportunities) {}

    public function handle(QuoteCreated|QuoteNegotiationRecorded|QuoteConverted $event): void
    {
        if ($event->opportunityId === null) {
            return;
        }

        $this->opportunities->syncFromQuote($event->opportunityId, match (true) {
            $event instanceof QuoteCreated => 'created',
            $event instanceof QuoteNegotiationRecorded => 'negotiation',
            $event instanceof QuoteConverted => 'converted',
        });
    }
}
