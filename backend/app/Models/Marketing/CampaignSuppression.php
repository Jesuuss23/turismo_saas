<?php

namespace App\Models\Marketing;

use App\Models\TenantModel;

/** Contacto del segmento excluido del envío con su motivo (spec 14 §3.9). */
class CampaignSuppression extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    public const UPDATED_AT = null;

    public const REASONS = ['optout' => 'Baja', 'nodata' => 'Sin dato de contacto', 'consent' => 'Sin consentimiento', 'fatigue' => 'Fatiga (72 h)'];

    protected $table = 'mkt_campaign_suppressions';

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
