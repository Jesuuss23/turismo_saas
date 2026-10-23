<?php

namespace App\Providers;

use App\Events\Quotes\QuoteConverted;
use App\Events\Quotes\QuoteCreated;
use App\Events\Quotes\QuoteNegotiationRecorded;
use App\Listeners\Crm\SyncOpportunityFromQuote;
use App\Services\Crm\CrmCatalog;
use App\Services\Crm\CrmSearchProvider;
use App\Services\Crm\Linked\LinkedData;
use App\Services\Crm\Linked\NullLinkedData;
use App\Services\Search\GlobalSearch;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Módulo CRM (spec 01). */
class CrmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Cotizaciones/Reservas/Finanzas reemplazarán este binding con su implementación.
        $this->app->bindIf(LinkedData::class, NullLinkedData::class);
        $this->app->scoped(CrmCatalog::class);
    }

    public function boot(): void
    {
        $this->app->make(GlobalSearch::class)->register(CrmSearchProvider::class);

        Event::listen([QuoteCreated::class, QuoteNegotiationRecorded::class, QuoteConverted::class], SyncOpportunityFromQuote::class);
    }
}
