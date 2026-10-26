<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;

/** Incluye / No incluye. */
class ProductInclusion extends TenantModel
{
    protected $table = 'catalog_product_inclusions';
}
