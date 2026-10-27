<?php

namespace App\Models\Crm;

use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;

/** Política de bonos por tramos del tenant (spec 14 §3.16). */
class SalesTargetPolicy extends TenantModel
{
    use OptimisticLock;

    protected $table = 'crm_sales_target_policies';

    protected function casts(): array
    {
        return ['tiers' => 'array'];
    }
}
