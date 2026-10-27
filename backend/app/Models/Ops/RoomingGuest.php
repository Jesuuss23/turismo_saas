<?php

namespace App\Models\Ops;

use App\Models\Bookings\Passenger;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pasajero en una habitación (§3.9); EXCLUDE impide dos habitaciones en fechas superpuestas (RT-13). */
class RoomingGuest extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'ops_rooming_guests';

    public function room(): BelongsTo
    {
        return $this->belongsTo(RoomingRoom::class, 'room_id');
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(Passenger::class, 'passenger_id');
    }
}
