<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;

/** Precio B2B por agencia. */
class ProductB2bRate extends TenantModel
{
    protected $table = 'catalog_product_b2b_rates';

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'commission_pct' => 'decimal:2',
            'is_credit' => 'boolean',
            'valid_to' => 'date',
        ];
    }
}
