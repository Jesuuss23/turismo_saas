<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Hotel ofrecido en un tramo (spec 16 §3.6). */
class AccommodationHotel extends TenantModel
{
    public const CATEGORIES = ['3 estrellas', '4 estrellas', '5 estrellas', 'Hostal', 'Lodge', 'Boutique'];

    protected $table = 'catalog_accommodation_hotels';

    public function roomTypes(): HasMany
    {
        return $this->hasMany(AccommodationRoomType::class, 'hotel_id')->orderBy('price_per_night');
    }
}
