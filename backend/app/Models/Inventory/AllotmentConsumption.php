<?php

namespace App\Models\Inventory;

use App\Models\Bookings\BookedService;
use App\Models\Bookings\Reservation;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Consumo de un servicio de reserva sobre una fecha del cupo (spec 11 §3.3). */
class AllotmentConsumption extends TenantModel
{
    protected $table = 'catalog_allotment_consumptions';

    public function day(): BelongsTo
    {
        return $this->belongsTo(AllotmentDay::class, 'allotment_day_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(BookedService::class, 'booking_service_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
