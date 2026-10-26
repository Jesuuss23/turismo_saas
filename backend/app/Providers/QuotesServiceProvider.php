<?php

namespace App\Providers;

use App\Services\Catalog\Links\CatalogLinks;
use App\Services\Crm\Linked\LinkedData;
use App\Services\Quotes\QuoteCatalogLinks;
use App\Services\Quotes\QuoteSearchProvider;
use App\Services\Quotes\QuotesLinkedData;
use App\Services\Search\GlobalSearch;
use Illuminate\Support\ServiceProvider;

/** Módulo Cotizaciones (spec 03). Se registra antes de Catálogo y CRM: sus bindings ganan el `bindIf`. */
class QuotesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Reservas/Finanzas extenderán estas implementaciones.
        $this->app->bindIf(LinkedData::class, QuotesLinkedData::class);
        $this->app->bindIf(CatalogLinks::class, QuoteCatalogLinks::class);
    }

    public function boot(): void
    {
        $this->app->make(GlobalSearch::class)->register(QuoteSearchProvider::class);
    }
}
