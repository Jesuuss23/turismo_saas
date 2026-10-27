<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\Integrations\ApiKeyService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\LimitService;
use App\Services\Platform\PlatformClock;
use App\Services\Platform\UsageMeter;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guard `api-key` de la API pública (spec 13 API-BR-01, §8.3). Uso: `api.key:reservations:read`.
 * Bearer → key vigente (401) → IP allowlist (403) → tenant activo → feature `api_access` (403) → scope (403)
 * → rate limit por key con ráfaga (429 RATE_LIMITED) → cuota mensual (429 QUOTA_EXCEEDED) → medición y headers X-RateLimit-* / X-Quota-*.
 */
class AuthenticateApiKey
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
        private readonly LimitService $limits,
        private readonly UsageMeter $meter,
    ) {}

    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $token = (string) $request->bearerToken();
        $key = $token === '' ? null : ApiKeyService::lookup($token);
        if (! $key) {
            throw new ApiException(401, 'API_KEY_INVALID', 'API-BR-01', 'API key inválida o ausente. Envíe Authorization: Bearer qts_…');
        }
        $today = PlatformClock::today();
        if ($key->status !== 'active' || ($key->expires_at && $key->expires_at < $today->toDateString())) {
            throw new ApiException(401, 'API_KEY_INVALID', 'API-BR-01', $key->status === 'revoked' ? 'La API key fue revocada.' : 'La API key está vencida.');
        }
        if (! ApiKeyService::ipAllowed($key, $request->ip())) {
            throw new ApiException(403, 'IP_NOT_ALLOWED', 'API-BR-01', "La IP {$request->ip()} no está en la allowlist de la API key.");
        }
        $this->context->set($key->tenant_id);
        $tenant = $this->context->tenant();
        if (! $tenant?->isActive()) {
            throw $tenant?->suspension_source === 'billing'
                ? new ApiException(402, 'PAYMENT_REQUIRED', 'PAYMENT_REQUIRED', 'La cuenta está suspendida por falta de pago.')
                : ApiException::tenantSuspended();
        }
        if (! $this->entitlements->enabled($key->tenant_id, 'api_access')) {
            throw new ApiException(403, 'FEATURE_NOT_IN_PLAN', 'FEATURE_NOT_IN_PLAN', 'El plan no incluye la API pública.', [], ['feature' => 'api_access']);
        }
        if ($scope !== null && ! in_array($scope, json_decode($key->scopes, true), true)) {
            throw new ApiException(403, 'INSUFFICIENT_SCOPE', 'API-BR-01', "La API key no tiene el scope {$scope}.", [$scope]);
        }

        $limits = $this->limits->limits($key->tenant_id);
        $rate = $limits['api_rate_per_min']['value'] ?? 6000;
        $burst = max(1, (int) ceil($rate / 6));
        $minuteKey = "rl:{$key->tenant_id}:{$key->id}";
        $burstKey = "rlb:{$key->tenant_id}:{$key->id}";
        if ($rate === 0 || RateLimiter::tooManyAttempts($minuteKey, $rate) || RateLimiter::tooManyAttempts($burstKey, $burst)) {
            $this->countThrottle($key->id);
            $retry = max(1, RateLimiter::availableIn(RateLimiter::tooManyAttempts($minuteKey, $rate) ? $minuteKey : $burstKey));

            throw new ApiException(429, 'RATE_LIMITED', 'SUB-BR-04', "Superó el límite de {$rate} solicitudes por minuto. Reintente en {$retry} s.", [],
                ['retry_after' => $retry], ['Retry-After' => (string) $retry, 'X-RateLimit-Limit' => (string) $rate, 'X-RateLimit-Remaining' => '0']);
        }
        $quota = $limits['api_calls_month']['value'];
        $used = $this->meter->liveMonth($key->tenant_id, 'api_calls_month');
        if ($quota !== null && $used >= $quota) {
            $this->countThrottle($key->id);
            $retry = (int) max(1, PlatformClock::now()->diffInSeconds($today->copy()->addMonthNoOverflow()->startOfMonth()));

            throw new ApiException(429, 'QUOTA_EXCEEDED', 'SUB-BR-04', "Alcanzó la cuota mensual de {$quota} llamadas de su plan.", [], ['retry_after' => $retry],
                ['Retry-After' => (string) $retry, 'X-Quota-Limit' => (string) $quota, 'X-Quota-Remaining' => '0']);
        }
        RateLimiter::hit($minuteKey, 60);
        RateLimiter::hit($burstKey, 1);
        $this->meter->increment($key->tenant_id, 'api_calls_month');
        $this->increment(ApiKeyService::counterKey($key->id, 'calls', $today));
        if (Cache::add("apikey:{$key->id}:touch", 1, 60)) {
            DB::table('integration_api_keys')->where('id', $key->id)->update(['last_used_at' => now(), 'last_used_ip' => $request->ip()]);
        }
        $request->attributes->set('api_key', $key);

        $response = $next($request);
        $response->headers->add([
            'X-RateLimit-Limit' => (string) $rate, 'X-RateLimit-Remaining' => (string) max(0, RateLimiter::remaining($minuteKey, $rate)),
            'X-RateLimit-Reset' => (string) (time() + RateLimiter::availableIn($minuteKey)),
        ] + ($quota !== null ? ['X-Quota-Limit' => (string) $quota, 'X-Quota-Remaining' => (string) max(0, $quota - $used - 1)] : []));

        return $response;
    }

    private function countThrottle(string $keyId): void
    {
        $this->increment(ApiKeyService::counterKey($keyId, '429', PlatformClock::today()));
    }

    private function increment(string $key): void
    {
        Cache::add($key, 0, UsageMeter::KEY_TTL);
        Cache::increment($key);
    }
}
