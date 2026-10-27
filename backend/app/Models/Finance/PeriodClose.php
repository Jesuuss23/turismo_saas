<?php

namespace App\Models\Finance;

use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Periodo contable (§4.8). */
class PeriodClose extends TenantModel
{
    use OptimisticLock;

    protected $table = 'fin_period_closes';

    protected function casts(): array
    {
        return ['closed_at' => 'datetime', 'snapshot' => 'array'];
    }

    public function checks(): HasMany
    {
        return $this->hasMany(PeriodCloseCheck::class);
    }
}
