<?php

namespace App\Models\Experience;

use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;

/** Configuración del widget del tenant: clave pública y predeterminados (spec 17 §3.8). */
class WidgetConfig extends TenantModel
{
    use OptimisticLock;

    protected $table = 'channel_widget_config';

    protected function casts(): array
    {
        return ['prev_expires_at' => 'datetime', 'defaults' => 'array', 'reviews_widget' => 'array'];
    }
}
