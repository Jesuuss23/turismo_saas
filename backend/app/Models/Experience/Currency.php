<?php

namespace App\Models\Experience;

use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;

/** Moneda del tenant con su TC vigente en soles por unidad (spec 17 §3.6). */
class Currency extends TenantModel
{
    use OptimisticLock;

    protected $table = 'fin_currencies';

    protected function casts(): array
    {
        return ['rate_pen' => 'decimal:6', 'active' => 'boolean', 'is_base' => 'boolean', 'is_charge' => 'boolean'];
    }
}
