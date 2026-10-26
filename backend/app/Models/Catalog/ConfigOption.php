<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;

/** Opción de personalización con diferencia de precio. */
class ConfigOption extends TenantModel
{
    protected $table = 'catalog_product_config_options';

    protected function casts(): array
    {
        return [
            'price_delta' => 'decimal:2',
        ];
    }
}
