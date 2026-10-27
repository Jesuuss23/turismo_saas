<?php

namespace App\Models\Finance;

use App\Models\TenantModel;

/** Tipo de cambio diario (§3.19). */
class FxRate extends TenantModel
{
    protected $table = 'fin_fx_rates';

    protected function casts(): array
    {
        return ['rate_date' => 'date', 'buy' => 'decimal:6', 'sell' => 'decimal:6'];
    }
}
