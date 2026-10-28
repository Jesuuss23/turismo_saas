<?php

namespace App\Events\Bookings;

use Illuminate\Foundation\Events\Dispatchable;

/** Spec 18 §8: viaje completado → ScheduleReviewRequest. */
class ReservationCompleted
{
    use Dispatchable;

    public function __construct(public readonly string $reservationId) {}
}
