<?php

namespace App\Models\Bookings;

use App\Models\TenantModel;

/** Detalle económico de la cancelación (§4.6). */
class Cancellation extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'booking_cancellations';

    protected function casts(): array
    {
        return [
            'lines' => 'array', 'cancelled_at' => 'datetime', 'factor_k' => 'decimal:6',
            'total_at_cancel' => 'decimal:2', 'paid_at_cancel' => 'decimal:2', 'penalty_services' => 'decimal:2', 'consumed_amount' => 'decimal:2',
            'penalty_amount' => 'decimal:2', 'refund_amount' => 'decimal:2', 'owed_amount' => 'decimal:2',
        ];
    }
}
