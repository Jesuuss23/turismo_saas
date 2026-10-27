<?php

namespace App\Models\Finance;

use App\Models\TenantModel;

/** Aplicación de un pago a una CxC (PROC-042) y su desaplicación (PROC-043). */
class PaymentApplication extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'fin_payment_applications';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'applied_at' => 'datetime', 'reversed_at' => 'datetime'];
    }
}
