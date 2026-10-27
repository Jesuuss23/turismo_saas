<?php

namespace App\Models\Inventory;

use App\Models\Iam\User;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bitácora del contrato de cupo (spec 11 §3.6); `user_id` NULL = sistema. */
class AllotmentEvent extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    public $timestamps = false;

    protected $table = 'catalog_allotment_events';

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
