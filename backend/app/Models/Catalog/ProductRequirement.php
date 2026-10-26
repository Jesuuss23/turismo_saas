<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;

/** Requisitos del producto. */
class ProductRequirement extends TenantModel
{
    protected $table = 'catalog_product_requirements';
}
