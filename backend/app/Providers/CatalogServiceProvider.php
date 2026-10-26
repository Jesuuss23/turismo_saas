<?php

namespace App\Providers;

use App\Services\Catalog\CatalogSearchProvider;
use App\Services\Catalog\Integration\CatalogLinkedData;
use App\Services\Catalog\Links\CatalogLinks;
use App\Services\Catalog\Links\NullCatalogLinks;
use App\Services\Crm\Linked\LinkedData;
use App\Services\Search\GlobalSearch;
use Illuminate\Support\ServiceProvider;

/** Módulo Catálogo turístico (spec 02). Se registra antes del CRM: su LinkedData gana el `bindIf`. */
class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Cotizaciones/Reservas/Operaciones/Finanzas/B2B reemplazarán este binding.
        $this->app->bindIf(CatalogLinks::class, NullCatalogLinks::class);
        $this->app->bindIf(LinkedData::class, CatalogLinkedData::class);
    }

    public function boot(): void
    {
        $this->app->make(GlobalSearch::class)->register(CatalogSearchProvider::class);
    }
}
