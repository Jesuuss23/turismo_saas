<?php

namespace App\Models\Finance;

use App\Models\TenantModel;

/** Bitácora de la integración fiscal (PROC-064/065). */
class FiscalEvent extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    public const UPDATED_AT = null;

    protected $table = 'fin_fiscal_events';

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
