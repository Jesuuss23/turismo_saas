<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;

/** Reglas de pasajeros del producto (spec 16 §3.4); sin fila se usan los defaults del tenant. */
class PaxRule extends TenantModel
{
    protected $table = 'catalog_pax_rules';

    protected function casts(): array
    {
        return [
            'adult_min_age' => 'integer', 'child_min_age' => 'integer', 'child_max_age' => 'integer', 'infant_max_age' => 'integer',
            'child_pays' => 'boolean', 'child_price_pct' => 'decimal:2', 'infant_allowed' => 'boolean', 'infant_pays' => 'boolean',
            'max_children_per_adult' => 'integer', 'max_infants_per_adult' => 'integer', 'min_adults' => 'integer',
        ];
    }
}
