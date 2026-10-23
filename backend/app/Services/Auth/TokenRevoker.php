<?php

namespace App\Services\Auth;

use App\Models\Iam\User;
use App\Models\Sanctum\PersonalAccessToken;

/** Revocación masiva (§3.4): baja, cambio de rol, reset MFA (por usuario) y suspensión (por tenant). */
class TokenRevoker
{
    public function forUser(User $user, ?int $exceptTokenId = null): int
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey())
            ->when($exceptTokenId, fn ($q) => $q->whereKeyNot($exceptTokenId))
            ->delete();
    }

    public function forTenant(string $tenantId): int
    {
        return PersonalAccessToken::where('tenant_id', $tenantId)->delete();
    }
}
