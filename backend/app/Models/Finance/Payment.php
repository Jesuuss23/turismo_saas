<?php

namespace App\Models\Finance;

use App\Models\TenantModel;

/** Pago de cliente o reembolso (módulo Finanzas). */
class Payment extends TenantModel
{
    /** Pagos que cuentan como cobrados (§12-2). */
    public const COUNTED = ['aplicado', 'confirmado'];

    protected $table = 'fin_payments';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'date'];
    }
}
