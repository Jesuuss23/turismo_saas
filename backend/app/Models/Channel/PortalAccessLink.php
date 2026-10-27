<?php

namespace App\Models\Channel;

use App\Models\TenantModel;

/** Enlace de acceso del pasajero (spec 08 §3.13, PPX-BR-02): solo se guarda el hash del token. */
class PortalAccessLink extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'channel_portal_access_links';

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
