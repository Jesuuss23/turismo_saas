<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;

/** Regla de pricing del tenant (PROC-018). */
class PriceRule extends TenantModel
{
    public const KINDS = ['Markup %', 'Markup fijo', 'Descuento %', 'Descuento fijo', 'Impuesto %', 'Comisión %', 'Redondeo', 'Precio mínimo', 'Precio máximo'];

    protected $table = 'catalog_price_rules';

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'min_pax' => 'integer',
            'priority' => 'integer',
        ];
    }
}
