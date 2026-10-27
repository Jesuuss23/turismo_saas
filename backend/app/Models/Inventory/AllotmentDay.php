<?php

namespace App\Models\Inventory;

use App\Models\Iam\User;
use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Una fecha de un contrato de cupo (spec 11 §3.2): disponible = bloqueado − liberado − usado. */
class AllotmentDay extends TenantModel
{
    use OptimisticLock;

    protected $table = 'catalog_allotment_days';

    protected function casts(): array
    {
        return ['service_date' => 'date', 'release_deadline' => 'date', 'retained_at' => 'datetime', 'closed_at' => 'datetime',
            'blocked_qty' => 'integer', 'released_qty' => 'integer', 'used_qty' => 'integer', 'pending_qty' => 'integer'];
    }

    public function allotment(): BelongsTo
    {
        return $this->belongsTo(Allotment::class)->withTrashed();
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(AllotmentConsumption::class);
    }

    public function charge(): HasOne
    {
        return $this->hasOne(AllotmentCharge::class);
    }

    public function retainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retained_by');
    }

    public function available(): int
    {
        return $this->blocked_qty - $this->released_qty - $this->used_qty;
    }

    /** Cupo vigente (bloqueado menos liberado). */
    public function current(): int
    {
        return $this->blocked_qty - $this->released_qty;
    }
}
