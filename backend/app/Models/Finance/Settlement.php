<?php

namespace App\Models\Finance;

use App\Models\TenantModel;

/** Liquidación / cierre financiero de una reserva (FIN-BR-36/37). */
class Settlement extends TenantModel
{
    protected $table = 'fin_settlements';

    protected function casts(): array
    {
        return ['closed_at' => 'datetime', 'income_pen' => 'decimal:2', 'cost_pen' => 'decimal:2', 'commissions_pen' => 'decimal:2', 'net_pen' => 'decimal:2', 'gross_margin_pct' => 'decimal:2', 'snapshot' => 'array'];
    }
}
