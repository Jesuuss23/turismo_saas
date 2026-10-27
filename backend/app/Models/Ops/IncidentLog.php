<?php

namespace App\Models\Ops;

use App\Models\Iam\User;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bitácora append-only de la incidencia (§3.11). */
class IncidentLog extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    public const UPDATED_AT = null;

    protected $table = 'ops_incident_logs';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
