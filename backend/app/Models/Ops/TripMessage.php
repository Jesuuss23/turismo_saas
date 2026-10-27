<?php

namespace App\Models\Ops;

use App\Models\TenantModel;

/** Chat pasajero ↔ agencia (§3.14). */
class TripMessage extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'channel_trip_messages';

    protected function casts(): array
    {
        return ['quick_reply' => 'boolean', 'read_at' => 'datetime'];
    }
}
