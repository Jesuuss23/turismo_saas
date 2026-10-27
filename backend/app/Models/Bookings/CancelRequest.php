<?php

namespace App\Models\Bookings;

use App\Models\TenantModel;

/** Solicitud de anulación Ventas → Gerencia (RES-BR-33). */
class CancelRequest extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'booking_cancel_requests';

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'decided_at' => 'datetime'];
    }
}
