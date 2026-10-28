<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;

/** Modalidades de pago del producto (spec 16 §3.7). */
class PaymentPolicy extends TenantModel
{
    protected $table = 'catalog_payment_policies';

    protected function casts(): array
    {
        return ['modes' => 'array', 'deposit_pct' => 'decimal:2', 'balance_due_days' => 'integer'];
    }
}
