<?php

namespace App\Models\Bookings;

use App\Models\TenantModel;

/** Modificación (PROC-027) o reprogramación (PROC-029). */
class BookingChange extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    public const TYPES = ['pax' => 'Agregar pasajeros', 'servicio' => 'Servicio adicional', 'upgrade' => 'Upgrade', 'otro' => 'Otro ajuste', 'reprogramacion' => 'Reprogramación'];

    protected $table = 'booking_changes';

    protected function casts(): array
    {
        return [
            'extra_pax' => 'integer',
            'service_date' => 'date',
            'delta_amount' => 'decimal:2',
            'credit_amount' => 'decimal:2',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'applied_at' => 'datetime',
            'payload' => 'array',
        ];
    }
}
