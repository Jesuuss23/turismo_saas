<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Spec 02 §3.1. */
class ProductType extends TenantModel
{
    use SoftDeletes;

    protected $table = 'catalog_product_types';

    protected function casts(): array
    {
        return [
            'requires_itinerary' => 'boolean',
            'requires_components' => 'boolean',
        ];
    }
}
