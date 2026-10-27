<?php

namespace App\Models\Crm;

use App\Models\Iam\User;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Equipo de ventas (spec 14 §3.13). */
class SalesTeam extends TenantModel
{
    protected $table = 'crm_sales_teams';

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(SalesTeamMember::class, 'team_id');
    }
}
