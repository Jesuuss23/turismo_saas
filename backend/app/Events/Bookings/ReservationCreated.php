<?php

namespace App\Events\Bookings;

use Illuminate\Foundation\Events\Dispatchable;

class ReservationCreated
{
    use Dispatchable;

    public function __construct(public readonly string $reservationId) {}
}
