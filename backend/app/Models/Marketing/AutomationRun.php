<?php

namespace App\Models\Marketing;

use App\Models\Iam\User;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ejecución de una automatización (spec 14 §3.12). */
class AutomationRun extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    public const UPDATED_AT = null;

    protected $table = 'mkt_automation_runs';

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
