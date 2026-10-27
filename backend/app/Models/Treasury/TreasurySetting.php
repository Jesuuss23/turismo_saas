<?php

namespace App\Models\Treasury;

use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;

/** Parámetros de tesorería, una fila por tenant (spec 12 §3.9). */
class TreasurySetting extends TenantModel
{
    use OptimisticLock;

    protected $table = 'fin_treasury_settings';

    protected function casts(): array
    {
        return ['petty_tolerance' => 'decimal:2', 'max_petty_expense' => 'decimal:2', 'sworn_statement_max' => 'decimal:2', 'gateway_fee_pct' => 'decimal:2',
            'replenish_threshold_pct' => 'integer', 'cash_deposit_hours' => 'integer', 'recurring_outflows' => 'array'];
    }
}
