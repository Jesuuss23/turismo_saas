<?php

namespace App\Models\Finance;

use App\Models\TenantModel;

/** Cierre de conciliación (FIN-BR-55). */
class ReconciliationClosure extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'fin_reconciliation_closures';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['closed_at' => 'datetime', 'credits' => 'decimal:2', 'debits' => 'decimal:2', 'net' => 'decimal:2'];
    }
}
