<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Actividad de un día del itinerario. */
class ItineraryItem extends TenantModel
{
    protected $table = 'catalog_itinerary_items';

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'price' => 'decimal:2',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(SupplierService::class, 'service_id');
    }
}
