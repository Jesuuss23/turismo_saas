<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;

/** Tipo de habitación de un hotel: capacidad y precio por noche (spec 16 §3.6). */
class AccommodationRoomType extends TenantModel
{
    public const TYPES = ['Simple' => 1, 'Doble' => 2, 'Matrimonial' => 2, 'Triple' => 3, 'Familiar' => 4, 'Suite' => 2];

    protected $table = 'catalog_accommodation_room_types';

    protected function casts(): array
    {
        return ['capacity' => 'integer', 'price_per_night' => 'decimal:2'];
    }
}
