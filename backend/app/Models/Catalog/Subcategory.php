<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Spec 02 §3.2. */
class Subcategory extends TenantModel
{
    use SoftDeletes;

    protected $table = 'catalog_product_subcategories';

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
