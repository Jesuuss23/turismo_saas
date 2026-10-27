<?php

namespace App\Models\Collab;

use App\Models\Iam\User;
use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Solicitud de aprobación unificada (spec 10 §3.2): polimórfica sobre el registro de origen. */
class ApprovalRequest extends TenantModel
{
    use OptimisticLock;

    public const LIVE = ['pendiente', 'info'];

    protected $table = 'collab_approval_requests';

    protected array $generated = ['amount_base'];

    protected function casts(): array
    {
        return [
            'is_generic' => 'boolean', 'closed_in_origin' => 'boolean', 'amount' => 'decimal:2', 'amount_base' => 'decimal:2', 'fx_rate' => 'decimal:6',
            'origin_snapshot' => 'array', 'due_at' => 'datetime', 'paused_at' => 'datetime', 'decided_at' => 'datetime', 'level_started_at' => 'datetime',
            'sla_warned_at' => 'datetime', 'sla_overdue_at' => 'datetime',
        ];
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_to');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ApprovalRule::class, 'current_rule_id')->withTrashed();
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class, 'request_id')->orderBy('level');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ApprovalEvent::class, 'request_id')->orderBy('occurred_at');
    }

    public function infoRequests(): HasMany
    {
        return $this->hasMany(ApprovalInfoRequest::class, 'request_id')->orderBy('asked_at');
    }
}
