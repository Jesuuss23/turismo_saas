<?php

namespace App\Models\Marketing;

use App\Models\TenantModel;

/** Disparador procesado (contacto + referencia): nunca se envía dos veces (MKT-BR-17). */
class AutomationEnrollment extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    public $timestamps = false;

    protected $table = 'mkt_automation_enrollments';

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }
}
