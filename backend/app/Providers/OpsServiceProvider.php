<?php

namespace App\Providers;

use App\Events\Bookings\ReservationCancelled;
use App\Models\Bookings\Reservation;
use App\Services\Catalog\Links\CatalogLinks;
use App\Services\Ops\Integration\OpsCatalogLinks;
use App\Services\Ops\OrderService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Módulo Operaciones (spec 05). Se registra antes de Reservas: sus bindings ganan el `bindIf`. */
class OpsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(CatalogLinks::class, OpsCatalogLinks::class);
    }

    public function boot(): void
    {
        // §8.1: cancelar la reserva cancela la orden, libera recursos y anula vouchers vigentes.
        Event::listen(ReservationCancelled::class, function (ReservationCancelled $event) {
            if ($reservation = Reservation::find($event->reservationId)) {
                app(OrderService::class)->cancelForReservation($reservation, Auth::user());
            }
        });
    }
}
