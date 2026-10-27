<?php

namespace App\Models\Finance;

use App\Models\TenantModel;

/** Control manual marcado del cierre de periodo (FIN-BR-57). */
class PeriodCloseCheck extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'fin_period_close_checks';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['checked_at' => 'datetime'];
    }
}
