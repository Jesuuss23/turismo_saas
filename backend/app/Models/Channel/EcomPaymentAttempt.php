<?php

namespace App\Models\Channel;

use App\Models\TenantModel;

/** Intento de cobro con tarjeta (spec 08 §3.8): nunca PAN ni CVV (ECO-BR-16). */
class EcomPaymentAttempt extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'channel_ecom_payment_attempts';

    protected function casts(): array
    {
        return ['attempted_at' => 'datetime'];
    }
}
