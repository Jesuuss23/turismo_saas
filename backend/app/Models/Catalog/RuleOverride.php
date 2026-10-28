<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;

/** Override de una regla del centro para un producto (spec 16 §3.2). */
class RuleOverride extends TenantModel
{
    protected $table = 'catalog_product_rule_overrides';

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'params' => 'array'];
    }
}
