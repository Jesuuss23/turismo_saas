<?php

namespace App\Models\Finance;

use App\Models\TenantModel;

/** Línea de comprobante (una por comprobante en el MVP). */
class InvoiceLine extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'fin_invoice_lines';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_value' => 'decimal:2', 'line_amount' => 'decimal:2', 'igv_amount' => 'decimal:2'];
    }
}
