<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;

/** Condición estructurada de tarifa (propuesta §3.8). */
class RateCondition extends TenantModel
{
    protected $table = 'catalog_rate_conditions';

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
