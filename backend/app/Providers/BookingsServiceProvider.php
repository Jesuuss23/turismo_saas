<?php

namespace App\Providers;

use App\Services\Bookings\Integration\ReservationCatalogLinks;
use App\Services\Bookings\Integration\ReservationSearchProvider;
use App\Services\Bookings\Integration\ReservationsLinkedData;
use App\Services\Catalog\Links\CatalogLinks;
use App\Services\Crm\Linked\LinkedData;
use App\Services\Search\GlobalSearch;
use Illuminate\Support\ServiceProvider;

/** Módulo Reservas (spec 04). Se registra antes de Cotizaciones: sus bindings ganan el `bindIf`. */
class BookingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Finanzas y Operaciones extenderán estas implementaciones.
        $this->app->bindIf(LinkedData::class, ReservationsLinkedData::class);
        $this->app->bindIf(CatalogLinks::class, ReservationCatalogLinks::class);
    }

    public function boot(): void
    {
        $this->app->make(GlobalSearch::class)->register(ReservationSearchProvider::class);
    }
}
