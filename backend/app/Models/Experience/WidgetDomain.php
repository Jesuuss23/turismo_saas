<?php

namespace App\Models\Experience;

use App\Models\TenantModel;

/** Dominio autorizado para incrustar el widget (WG-01). */
class WidgetDomain extends TenantModel
{
    protected $table = 'channel_widget_domains';

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'last_check_at' => 'datetime'];
    }
}
