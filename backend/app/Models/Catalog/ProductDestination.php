<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Destinos secundarios del producto. */
class ProductDestination extends TenantModel
{
    protected $table = 'catalog_product_destinations';

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class, 'destination_id');
    }
}
