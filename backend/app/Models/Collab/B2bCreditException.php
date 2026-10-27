<?php

namespace App\Models\Collab;

use App\Models\TenantModel;

/** Ampliación de la línea de crédito B2B aprobada en la bandeja (spec 10 §3.11). */
class B2bCreditException extends TenantModel
{
    public const AUDIT_COLUMNS = ['created_by'];

    public const UPDATED_AT = null;

    protected $table = 'channel_b2b_credit_exceptions';

    protected function casts(): array
    {
        return ['valid_until' => 'date'];
    }
}
