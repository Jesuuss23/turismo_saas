<?php

namespace App\Models\Crm;

use App\Models\Iam\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bitácora del caso (spec 01 §3.11). Solo INSERT. */
class CaseEvent extends CrmModel
{
    public const AUDIT_COLUMNS = [];

    public const UPDATED_AT = null;

    protected $table = 'crm_case_events';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
