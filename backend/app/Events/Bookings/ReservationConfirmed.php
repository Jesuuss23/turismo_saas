<?php

namespace App\Events\Bookings;

use Illuminate\Foundation\Events\Dispatchable;

class ReservationConfirmed
{
    use Dispatchable;

    public function __construct(public readonly string $reservationId) {}
}
