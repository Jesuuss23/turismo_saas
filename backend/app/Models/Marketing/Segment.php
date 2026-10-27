<?php

namespace App\Models\Marketing;

use App\Models\Iam\User;
use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Segmento dinámico (spec 14 §3.4): reglas evaluadas al momento del envío (MKT-BR-13). */
class Segment extends TenantModel
{
    use OptimisticLock, SoftDeletes;

    protected $table = 'mkt_segments';

    protected function casts(): array
    {
        return ['rules' => 'array'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }
}
