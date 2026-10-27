<?php

namespace App\Models\Ops;

use App\Models\TenantModel;

/** Confirmación de lectura de una alerta por el pasajero (spec 08 §3.14, PPX-BR-04). */
class IncidentPaxAck extends TenantModel
{
    public $timestamps = false;

    public const AUDIT_COLUMNS = [];

    protected $table = 'ops_incident_pax_acks';

    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime'];
    }
}
