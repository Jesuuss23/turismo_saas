<?php

namespace App\Models\Ops;

use App\Models\TenantModel;

/** Check-in por pasajero (§3.6); la marca es la del dispositivo (hecho de campo). */
class Checkin extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'ops_assignment_checkins';

    protected function casts(): array
    {
        return ['checked_at' => 'datetime', 'undone_at' => 'datetime'];
    }
}
