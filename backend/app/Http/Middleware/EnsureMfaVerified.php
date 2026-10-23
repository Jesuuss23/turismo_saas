<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\Sanctum\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-BR-08: un token sin MFA verificado solo sirve para el paso MFA.
 * Además aplica la expiración deslizante del back-office (§3.4).
 */
class EnsureMfaVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var PersonalAccessToken|null $token */
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || $token->isMfaPending()) {
            throw ApiException::mfaRequired();
        }

        $this->slide($token);

        return $next($request);
    }

    private function slide(PersonalAccessToken $token): void
    {
        if (! in_array($token->experience, ['backoffice', 'platform'], true) || $token->expires_at === null) {
            return;
        }

        $ttl = config("turismo.token_ttl.{$token->experience}");
        $refreshed = $token->expires_at->copy()->subMinutes($ttl);
        if ($refreshed->diffInMinutes(now()) >= config('turismo.token_sliding_refresh')) {
            $token->forceFill(['expires_at' => now()->addMinutes($ttl)])->save();
        }
    }
}
