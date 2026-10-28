<?php

namespace App\Models\Experience;

use App\Models\TenantModel;

/** Bitácora de moderación de una reseña propia (spec 18 §3.2), solo inserción. */
class OwnReviewLog extends TenantModel
{
    protected $table = 'crm_own_review_log';

    public const UPDATED_AT = null;

    public const AUDIT_COLUMNS = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
