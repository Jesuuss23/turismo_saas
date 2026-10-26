<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Asociación directa producto–temporada. */
class ProductSeason extends TenantModel
{
    protected $table = 'catalog_product_seasons';

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class, 'season_id');
    }
}
