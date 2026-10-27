<?php

namespace App\Models\Finance;

use App\Models\TenantModel;

/** Regla de comisión (FIN-BR-26). */
class CommissionRule extends TenantModel
{
    protected $table = 'fin_commission_rules';

    protected function casts(): array
    {
        return ['pct' => 'decimal:2', 'valid_from' => 'date', 'valid_to' => 'date', 'active' => 'boolean'];
    }
}
