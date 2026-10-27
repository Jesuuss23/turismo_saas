<?php

namespace App\Models\Crm;

use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;

/** Parámetros del libro de reclamaciones (spec 12 §3.11): plazo en días hábiles, alerta y conservación. */
class ClaimBookSetting extends TenantModel
{
    use OptimisticLock;

    protected $table = 'crm_claim_book_settings';

    protected function casts(): array
    {
        return ['response_days' => 'integer', 'alert_days' => 'integer', 'retention_years' => 'integer'];
    }
}
