<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;

/** Galería del producto. */
class ProductMedia extends TenantModel
{
    protected $table = 'catalog_product_media';

    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
