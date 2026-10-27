<?php

namespace App\Models\Collab;

use App\Models\Iam\User;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Decisión por nivel (spec 10 §3.3). */
class ApprovalStep extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'collab_approval_steps';

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ApprovalRule::class, 'rule_id')->withTrashed();
    }
}
