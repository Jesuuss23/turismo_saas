<?php

namespace App\Models\Marketing;

use App\Models\Iam\User;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Envío de prueba a un usuario interno (spec 14 §3.7, MKT-BR-04/18). */
class CampaignTest extends TenantModel
{
    public const AUDIT_COLUMNS = ['created_by'];

    protected $table = 'mkt_campaign_tests';

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'invalidated_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
