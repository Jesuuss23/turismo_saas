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
    /** Zona horaria de negocio (fechas "hoy", vencimientos). */
    public function timezone(): string
    {
        return (string) data_get($this->settings, 'timezone', 'America/Lima');
    }

    /** Tipo de cambio vigente a PEN (spec 01 CRM-BR-48, `settings.fx`). */
    public function fxToPen(string $currency): float
    {
        return $currency === 'PEN' ? 1.0 : (float) data_get($this->settings, "fx.{$currency}", $currency === 'USD' ? 3.75 : 1.0);
    }

    public function requiresMfaForInternalRoles(): bool
    {
        return (bool) data_get($this->settings, 'security.mfa_internal_required', true);
    }

    public function sedes(): HasMany
    {
        return $this->hasMany(Sede::class);
    }
}
