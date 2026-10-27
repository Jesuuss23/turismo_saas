<?php

namespace App\Providers;

use App\Events\Bookings\ReservationCancelled;
use App\Models\Bookings\BookedService;
use App\Services\Finance\PayableService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Extranet de proveedores (spec 06) y cuentas por pagar que genera. */
class SupplierServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Reserva cancelada: las CxP de sus servicios sin pagos se anulan.
        Event::listen(ReservationCancelled::class, function (ReservationCancelled $event) {
            app(PayableService::class)->voidFor(BookedService::where('reservation_id', $event->reservationId)->pluck('id')->all(), Auth::user(), 'reserva cancelada');
        });
    }
}
