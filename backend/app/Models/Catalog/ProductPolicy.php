<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;

/** Políticas comerciales (1:1). */
class ProductPolicy extends TenantModel
{
    protected $table = 'catalog_product_policies';
}
