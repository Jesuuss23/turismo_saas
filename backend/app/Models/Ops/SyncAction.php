<?php

namespace App\Models\Ops;

use App\Models\TenantModel;

/** Acción recibida del outbox móvil (§3.13): PK = client_action_id; base de idempotencia (MOB-BR-01). */
class SyncAction extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'ops_sync_actions';

    protected $guarded = ['tenant_id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'response' => 'array', 'occurred_at' => 'datetime', 'received_at' => 'datetime', 'captured_offline' => 'boolean'];
    }
}
