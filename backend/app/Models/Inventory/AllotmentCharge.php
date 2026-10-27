<?php

namespace App\Models\Inventory;

use App\Models\Finance\Payable;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Penalidad por cupo no usado ni liberado (spec 11 §3.5). `created_by` NULL = job. */
class AllotmentCharge extends TenantModel
{
    protected $table = 'catalog_allotment_charges';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'fx_rate' => 'decimal:6', 'qty' => 'integer'];
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(AllotmentDay::class, 'allotment_day_id');
    }

    public function payable(): BelongsTo
    {
        return $this->belongsTo(Payable::class);
    }
}
