<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Itinerario del producto (uno por defecto). */
class Itinerary extends TenantModel
{
    protected $table = 'catalog_itineraries';

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function days(): HasMany
    {
        return $this->hasMany(ItineraryDay::class, 'itinerary_id')->orderBy('day_number');
    }
}
