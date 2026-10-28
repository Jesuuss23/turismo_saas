<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tramo del circuito con noches por ciudad (spec 16 §3.6). */
class AccommodationLeg extends TenantModel
{
    protected $table = 'catalog_accommodation_legs';

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'nights' => 'integer'];
    }

    public function hotels(): HasMany
    {
        return $this->hasMany(AccommodationHotel::class, 'leg_id')->orderBy('name');
    }
}
