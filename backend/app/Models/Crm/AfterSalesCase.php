<?php

namespace App\Models\Crm;

use App\Models\Iam\User;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Caso de postventa: reclamo o feedback (spec 01 §3.10, §4.4). */
class AfterSalesCase extends CrmModel
{
    use OptimisticLock;

    public const OPEN = 'abierta';

    public const IN_PROGRESS = 'en_proceso';

    public const RESOLVED = 'resuelta';

    public const CLOSED = 'cerrada';

    protected $table = 'crm_cases';

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CaseEvent::class, 'case_id')->orderBy('created_at');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::OPEN, self::IN_PROGRESS], true);
    }
}
