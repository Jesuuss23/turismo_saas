<?php

namespace App\Models\Treasury;

use App\Models\Iam\User;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Historial de estados de la reposición (spec 12 §3.7). */
class ReplenishmentEvent extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    public const UPDATED_AT = null;

    protected $table = 'fin_cash_replenishment_events';

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
