<?php

namespace App\Models\Ops;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Checklist de la orden (§3.2): no bloquea el cierre (OPS-BR-07). */
class OrderTask extends TenantModel
{
    use SoftDeletes;

    protected $table = 'ops_order_tasks';

    protected function casts(): array
    {
        return ['done' => 'boolean', 'done_at' => 'datetime', 'position' => 'integer'];
    }
}
