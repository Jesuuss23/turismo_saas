<?php

namespace App\Models\Finance;

use App\Models\TenantModel;

/** Extracto bancario importado (PROC-049). */
class BankStatement extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'fin_bank_statements';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['imported_at' => 'datetime'];
    }
}
