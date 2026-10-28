<?php

namespace App\Models\Sys;

use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;

/** Regla del centro de reglas del tenant (spec 16 §3.1). */
class BookingRule extends TenantModel
{
    use OptimisticLock;

    protected $table = 'sys_booking_rules';

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'params' => 'array', 'scope' => 'array', 'product_override' => 'boolean', 'is_system' => 'boolean', 'params_schema' => 'array', 'sort_order' => 'integer'];
    }
}
