<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Plan de la plataforma (spec 09 §3.1). Catálogo global de solo lectura. */
class Plan extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['price_monthly' => 'decimal:2'];
    }

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }
}
