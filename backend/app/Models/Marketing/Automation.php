<?php

namespace App\Models\Marketing;

use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Automatización de marketing o de servicio (spec 14 §3.11). */
class Automation extends TenantModel
{
    use OptimisticLock;

    public const STATUS_LABELS = ['activa' => 'Activa', 'pausada' => 'Pausada'];

    protected $table = 'mkt_automations';

    protected function casts(): array
    {
        return ['last_run_at' => 'datetime'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class)->orderByDesc('started_at');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(AutomationEnrollment::class);
    }
}
