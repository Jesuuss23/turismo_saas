<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Día del itinerario. */
class ItineraryDay extends TenantModel
{
    protected $table = 'catalog_itinerary_days';

    protected function casts(): array
    {
        return [
            'day_number' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ItineraryItem::class, 'day_id')->orderByRaw('start_time NULLS LAST')->orderBy('created_at');
    }

    public function itinerary(): BelongsTo
    {
        return $this->belongsTo(Itinerary::class, 'itinerary_id');
    }
}
