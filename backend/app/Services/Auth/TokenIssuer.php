<?php

namespace App\Services\Auth;

use App\Models\Iam\PlatformUser;
use App\Models\Iam\User;
use App\Models\Sanctum\PersonalAccessToken;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;

/**
 * Emite tokens Sanctum extendidos (§3.4): tenant_id (única fuente del tenant), experiencia,
 * estado MFA y expiración por experiencia.
 */
class TokenIssuer
{
    public function issue(User|PlatformUser $user, bool $mfaPending, ?string $deviceName = null, ?string $ip = null): NewAccessToken
    {
        $experience = $user instanceof PlatformUser ? 'platform' : $user->experience();
        $abilities = $mfaPending ? [PersonalAccessToken::ABILITY_MFA] : ["experience:{$experience}"];
        // El paso MFA tiene que completarse rápido.
        $ttl = $mfaPending ? 10 : config("turismo.token_ttl.{$experience}");

        $plain = Str::random(48);

        /** @var PersonalAccessToken $token */
        $token = $user->tokens()->create([
            'name' => $deviceName ?? $experience,
            'token' => hash('sha256', $plain),
            'abilities' => $abilities,
            'expires_at' => now()->addMinutes($ttl),
            'tenant_id' => $user instanceof User ? $user->tenant_id : null,
            'experience' => $experience,
            'mfa_verified_at' => $mfaPending ? null : ($user instanceof PlatformUser || $user->requiresMfa() ? now() : null),
            'device_name' => $deviceName,
            'ip' => $ip,
        ]);

        return new NewAccessToken($token, $token->getKey().'|'.$plain);
    }
}
