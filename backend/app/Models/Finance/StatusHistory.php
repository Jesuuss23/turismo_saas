<?php

namespace App\Models\Finance;

use App\Models\TenantModel;

/** Historial de estados de pagos, comisiones, comprobantes y CxP (§3.5). */
class StatusHistory extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    public const UPDATED_AT = null;

    protected $table = 'fin_status_history';

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
