<?php

namespace App\Models;

use App\Models\Iam\Sede;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasUuids;

    public const ACTIVE = 'activo';

    public const SUSPENDED = 'suspendido';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'fx_rate' => 'decimal:4',
            'igv_pct' => 'decimal:2',
            'suspended_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** Política MFA del tenant para roles internos (SEC-BR-08, 09 §12-4). Por defecto obligatoria. */
    public function requiresMfaForInternalRoles(): bool
    {
        return (bool) data_get($this->settings, 'security.mfa_internal_required', true);
    }

    public function sedes(): HasMany
    {
        return $this->hasMany(Sede::class);
    }
}
