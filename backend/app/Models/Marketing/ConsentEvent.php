<?php

namespace App\Models\Marketing;

use App\Models\Iam\User;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Historial inmutable del consentimiento (spec 14 §3.3, MKT-BR-11): un trigger rechaza UPDATE/DELETE. */
class ConsentEvent extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    public const UPDATED_AT = null;

    protected $table = 'mkt_consent_events';

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
