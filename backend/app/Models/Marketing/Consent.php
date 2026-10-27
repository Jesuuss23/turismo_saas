<?php

namespace App\Models\Marketing;

use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Consentimiento vigente por titular y canal (spec 14 §3.2, Ley 29733). Nunca se borra: los cambios quedan en mkt_consent_events. */
class Consent extends TenantModel
{
    use OptimisticLock;

    public const STATUS_LABELS = ['otorgado' => 'Otorgado', 'revocado' => 'Revocado'];

    protected $table = 'mkt_consents';

    protected function casts(): array
    {
        return ['granted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function events(): HasMany
    {
        return $this->hasMany(ConsentEvent::class)->orderBy('occurred_at');
    }

    public function revokeCampaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'revoke_campaign_id');
    }
}
