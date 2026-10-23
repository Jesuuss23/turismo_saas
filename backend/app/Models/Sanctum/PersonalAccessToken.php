<?php

namespace App\Models\Sanctum;

use App\Tenancy\TenantContext;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Token extendido (§3.4). Al encontrarlo fija el tenant ANTES de cargar el usuario,
 * porque iam_users está bajo RLS: el token es la única fuente del tenant (SEC-BR-02).
 */
class PersonalAccessToken extends SanctumToken
{
    public const ABILITY_MFA = 'mfa:verify';

    protected $fillable = [
        'name', 'token', 'abilities', 'expires_at',
        'tenant_id', 'experience', 'mfa_verified_at', 'device_name', 'ip',
    ];

    protected $casts = [
        'abilities' => 'json',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'mfa_verified_at' => 'datetime',
    ];

    public static function findToken($token)
    {
        $found = parent::findToken($token);

        if ($found?->tenant_id !== null) {
            app(TenantContext::class)->set($found->tenant_id);
        }

        return $found;
    }

    /** Token emitido tras la contraseña y pendiente del paso MFA. */
    public function isMfaPending(): bool
    {
        return $this->abilities === [self::ABILITY_MFA];
    }
}
