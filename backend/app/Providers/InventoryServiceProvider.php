<?php

namespace App\Providers;

use App\Services\Inventory\AllotmentConsumer;
use App\Services\Ops\TravelDocs;
use Illuminate\Support\ServiceProvider;

/** Cupos y documentos de viaje (spec 11 §8): consumo derivado de los servicios de reserva y checklist documental. */
class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        AllotmentConsumer::register();
        TravelDocs::register();
    }
}
