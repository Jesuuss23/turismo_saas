<?php

namespace App\Models\Crm;

use App\Models\Iam\User;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Historial de la meta: crear, activar, ajustar (antes/después + motivo), anular, cerrar (spec 14 §3.15). */
class SalesTargetAdjustment extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    public $timestamps = false;

    protected $table = 'crm_sales_target_adjustments';

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'occurred_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
