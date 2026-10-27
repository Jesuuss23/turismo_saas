<?php

namespace App\Models\Crm;

use App\Models\Iam\User;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Integrante de un equipo con vigencia (spec 14 §3.13). */
class SalesTeamMember extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'crm_sales_team_members';

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_to' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
