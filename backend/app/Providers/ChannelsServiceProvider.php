<?php

namespace App\Providers;

use App\Events\Bookings\ReservationCancelled;
use App\Models\Bookings\Reservation;
use App\Services\Catalog\Links\CatalogLinks;
use App\Services\Channels\B2bCredit;
use App\Services\Channels\Integration\ChannelCatalogLinks;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Canales y portales (spec 08). Se registra primero: su binding de CatalogLinks gana el `bindIf`. */
class ChannelsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(CatalogLinks::class, ChannelCatalogLinks::class);
    }

    public function boot(): void
    {
        // B2B-BR-12: anular una reserva cargada a crédito libera lo pendiente de la línea.
        Event::listen(ReservationCancelled::class, function (ReservationCancelled $event) {
            $reservation = Reservation::withTrashed()->find($event->reservationId);
            if ($reservation?->agency_id) {
                app(B2bCredit::class)->releaseReservation($reservation, Auth::user());
            }
        });
    }
}
