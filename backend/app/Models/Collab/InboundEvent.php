<?php

namespace App\Models\Collab;

use App\Models\TenantModel;

/** Evento entrante de un canal (spec 10 §3.10): idempotencia por `external_event_id`. */
class InboundEvent extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    public $timestamps = false;

    protected $table = 'collab_inbound_events';

    protected function casts(): array
    {
        return ['payload' => 'array', 'received_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}
