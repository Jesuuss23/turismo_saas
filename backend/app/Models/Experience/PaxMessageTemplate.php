<?php

namespace App\Models\Experience;

use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;

/** Plantilla de mensaje al pasajero (spec 17 §3.4). */
class PaxMessageTemplate extends TenantModel
{
    use OptimisticLock;

    protected $table = 'notif_pax_message_templates';

    protected function casts(): array
    {
        return ['channels' => 'array', 'conditions' => 'array', 'offset_from_rule' => 'boolean', 'active' => 'boolean', 'offset_hours' => 'integer'];
    }
}
