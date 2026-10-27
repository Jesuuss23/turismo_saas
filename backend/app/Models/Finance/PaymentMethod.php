<?php

namespace App\Models\Finance;

use App\Models\TenantModel;

/** Medio de pago configurable (§3.9, FIN-BR-08). */
class PaymentMethod extends TenantModel
{
    protected $table = 'fin_payment_methods';

    protected function casts(): array
    {
        return ['requires_reference' => 'boolean', 'is_b2b_credit' => 'boolean', 'active' => 'boolean'];
    }
}
