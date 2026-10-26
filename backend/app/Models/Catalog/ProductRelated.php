<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Productos relacionados. */
class ProductRelated extends TenantModel
{
    protected $table = 'catalog_product_related';

    public function related(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'related_product_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
