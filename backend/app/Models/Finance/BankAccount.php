<?php

namespace App\Models\Finance;

use App\Models\TenantModel;

/** Cuenta bancaria de la agencia (origen de pagos a proveedores). */
class BankAccount extends TenantModel
{
    protected $table = 'fin_bank_accounts';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
