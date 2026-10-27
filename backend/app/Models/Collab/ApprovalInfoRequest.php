<?php

namespace App\Models\Collab;

use App\Models\Iam\User;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pedido de información (spec 10 §3.4). */
class ApprovalInfoRequest extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'collab_approval_info_requests';

    protected function casts(): array
    {
        return ['asked_at' => 'datetime', 'answered_at' => 'datetime'];
    }

    public function asker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asked_by');
    }

    public function answerer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }
}
