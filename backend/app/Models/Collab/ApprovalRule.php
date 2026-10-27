<?php

namespace App\Models\Collab;

use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Regla de la matriz de aprobación (spec 10 §3.1). */
class ApprovalRule extends TenantModel
{
    use OptimisticLock, SoftDeletes;

    protected $table = 'collab_approval_rules';

    protected function casts(): array
    {
        return ['min_amount_pen' => 'decimal:2', 'forbid_self_approval' => 'boolean', 'is_active' => 'boolean', 'is_origin_locked' => 'boolean'];
    }
}
