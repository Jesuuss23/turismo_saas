<?php

namespace App\Models\Experience;

use App\Models\TenantModel;

/** Proveedor de tipo de cambio (DEMO v2.2, spec 17 §3.6). */
class FxRateProvider extends TenantModel
{
    protected $table = 'fx_rate_providers';

    protected function casts(): array
    {
        return ['last_run_at' => 'datetime'];
    }
}
