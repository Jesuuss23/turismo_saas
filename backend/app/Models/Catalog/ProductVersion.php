<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;

/** Versión inmutable con snapshot (§4.2, PRD-BR-017). */
class ProductVersion extends TenantModel
{
    protected $table = 'catalog_product_versions';

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'valid_from' => 'date',
        ];
    }
}
