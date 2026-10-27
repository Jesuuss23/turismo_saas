<?php

namespace App\Models\Channel;

use App\Models\TenantModel;

/** Bitácora del ticket (spec 08 §3.12). */
class SupportTicketEvent extends TenantModel
{
    public $timestamps = false;

    public const AUDIT_COLUMNS = [];

    protected $table = 'channel_support_ticket_events';

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
