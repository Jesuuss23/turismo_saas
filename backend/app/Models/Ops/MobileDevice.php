<?php

namespace App\Models\Ops;

use App\Models\TenantModel;

/** Dispositivo móvil registrado (§3.13); el id lo genera la app al instalarse. */
class MobileDevice extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    public $incrementing = false;

    protected $table = 'ops_mobile_devices';

    protected $guarded = ['tenant_id'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'last_sync_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
