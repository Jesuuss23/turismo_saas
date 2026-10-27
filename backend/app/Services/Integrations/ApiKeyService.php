<?php

namespace App\Services\Integrations;

use App\Exceptions\ApiException;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Platform\LimitService;
use App\Services\Platform\PlatformCatalog;
use App\Services\Platform\PlatformClock;
use App\Services\Platform\PlatformEvents;
use App\Services\Platform\UsageMeter;
use App\Services\SequenceService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * API keys del tenant (spec 13 §3.11, §4.4, API-BR-01..03): el token `qts_{env}_` + 32 base62 se muestra una sola vez y se guarda
 * como SHA-256; el lookup es por prefijo (13 caracteres) con la conexión de plataforma y caché de 60 s que se invalida al revocar.
 */
class ApiKeyService
{
    public const STATUS = ['active' => 'Activa', 'revoked' => 'Revocada', 'expired' => 'Vencida'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly LimitService $limits,
        private readonly UsageMeter $meter,
        private readonly PlatformEvents $events,
    ) {}

    public function list(): array
    {
        $users = DB::table('iam_users')->pluck('name', 'id');

        return DB::table('integration_api_keys')->orderByDesc('created_at')->get()->map(fn ($k) => $this->row($k, $users[$k->created_by] ?? null))->all();
    }

    public function row(object $k, ?string $createdBy = null): array
    {
        $today = PlatformClock::today();

        return ['id' => $k->id, 'code' => $k->code, 'name' => $k->name, 'environment' => $k->environment, 'prefix' => $k->prefix, 'last4' => $k->last4,
            'masked' => "{$k->prefix}…{$k->last4}", 'scopes' => json_decode($k->scopes, true), 'ip_allowlist' => json_decode((string) $k->ip_allowlist, true) ?: [],
            'expires_at' => $k->expires_at, 'last_used_at' => $k->last_used_at, 'last_used_ip' => $k->last_used_ip, 'status' => $k->status,
            'status_label' => self::STATUS[$k->status], 'revoked_at' => $k->revoked_at, 'revoke_reason' => $k->revoke_reason, 'created_by' => $createdBy,
            'created_at' => $k->created_at, 'calls_today' => (int) Cache::get(self::counterKey($k->id, 'calls', $today), 0),
            'throttled_today' => (int) Cache::get(self::counterKey($k->id, '429', $today), 0)];
    }

    /** API-BR-02 (máximo según el plan) y API-BR-03 (token una sola vez). */
    public function create(array $data, User $actor): array
    {
        $tenant = $this->context->id();
        $this->limits->assertWithin($tenant, 'api_keys', 1, 'API-BR-02');
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw ApiException::invalid('name', 'Indique un nombre para la API key.');
        }
        $env = $data['environment'] ?? 'live';
        if (! in_array($env, ['live', 'test'], true)) {
            throw ApiException::invalid('environment', 'Entorno inválido.');
        }
        $scopes = array_values(array_unique(array_filter((array) ($data['scopes'] ?? []), 'is_string')));
        if ($scopes === [] || array_diff($scopes, array_keys(PlatformCatalog::SCOPES))) {
            throw ApiException::invalid('scopes', 'Elija al menos un scope válido.');
        }
        $ips = array_values(array_filter(array_map('trim', is_array($data['ip_allowlist'] ?? null) ? $data['ip_allowlist'] : explode(',', (string) ($data['ip_allowlist'] ?? '')))));
        foreach ($ips as $ip) {
            if (! self::validCidr($ip)) {
                throw ApiException::invalid('ip_allowlist', "IP o rango CIDR inválido: {$ip}.");
            }
        }
        $expires = $data['expires_at'] ?? null;
        if ($expires !== null && $expires !== '' && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $expires) || $expires <= PlatformClock::today()->toDateString())) {
            throw ApiException::invalid('expires_at', 'El vencimiento debe ser una fecha futura.');
        }
        do {
            $token = "qts_{$env}_".self::base62(32);
            $prefix = substr($token, 0, 13);
        } while (DB::connection(PlatformCatalog::CONNECTION)->table('integration_api_keys')->where('prefix', $prefix)->exists());
        $id = (string) Str::uuid7();
        $code = $this->sequences->next('api_key');
        DB::table('integration_api_keys')->insert(['id' => $id, 'tenant_id' => $tenant, 'code' => $code, 'name' => Str::limit($name, 115), 'environment' => $env,
            'prefix' => $prefix, 'last4' => substr($token, -4), 'token_hash' => hash('sha256', $token), 'scopes' => json_encode($scopes),
            'ip_allowlist' => $ips === [] ? null : json_encode($ips), 'expires_at' => $expires ?: null, 'status' => 'active', 'created_by' => $actor->id, 'created_at' => now()]);
        $this->audit->log('API key', $code, 'crear', null, "{$name} · {$prefix}… · ".implode(', ', $scopes), actor: $actor);
        $this->events->notifyTenant($tenant, 'api.key_creada', "Nueva API key {$code}", "{$actor->name} creó la API key «{$name}» ({$prefix}…) con scopes: ".implode(', ', $scopes).'.');

        return $this->row(DB::table('integration_api_keys')->where('id', $id)->first(), $actor->name) + ['token' => $token];
    }

    /** Revocación manual e irreversible: responde 401 de inmediato (se invalida la caché de autenticación). */
    public function revoke(string $id, string $reason, User $actor): array
    {
        $k = (Str::isUuid($id) ? DB::table('integration_api_keys')->where('id', $id)->first() : null) ?? throw ApiException::notFound();
        if ($k->status !== 'active') {
            throw ApiException::conflict('API_KEY_NOT_ACTIVE', 'La API key ya está '.mb_strtolower(self::STATUS[$k->status]).'.');
        }
        if (trim($reason) === '') {
            throw ApiException::invalid('reason', 'El motivo es obligatorio.');
        }
        DB::table('integration_api_keys')->where('id', $id)->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by' => $actor->id,
            'revoke_reason' => Str::limit($reason, 295)]);
        Cache::forget(self::cacheKey($k->prefix));
        $this->audit->log('API key', $k->code, 'revocar', 'Activa', "Revocada · {$reason}", actor: $actor);

        return $this->row(DB::table('integration_api_keys')->where('id', $id)->first());
    }

    /** `api-keys:expire` (diario, por tenant): vence las keys y avisa 7 días antes. */
    public function expire(): array
    {
        $today = PlatformClock::today();
        $expired = 0;
        foreach (DB::table('integration_api_keys')->where('status', 'active')->whereNotNull('expires_at')->get() as $k) {
            if ($k->expires_at < $today->toDateString()) {
                DB::table('integration_api_keys')->where('id', $k->id)->update(['status' => 'expired']);
                Cache::forget(self::cacheKey($k->prefix));
                $this->audit->log('API key', $k->code, 'vencer', 'Activa', 'Vencida', system: true);
                $expired++;
            } elseif ($k->expires_at === $today->copy()->addDays(7)->toDateString()) {
                $this->events->notifyTenant($k->tenant_id, 'api.key_por_vencer', "La API key {$k->code} vence en 7 días",
                    "La API key «{$k->name}» ({$k->prefix}…) vence el ".Carbon::parse($k->expires_at)->format('d/m/Y').'. Cree una nueva y actualice su integración.');
            }
        }

        return ['expired' => $expired];
    }

    /** Pantalla «Límites»: rate y cuota del plan, consumo del mes y uso/429 de hoy por key. */
    public function limitsView(): array
    {
        $tenant = $this->context->id();
        $limits = $this->limits->limits($tenant);
        $used = $this->meter->liveMonth($tenant, 'api_calls_month');
        $plans = DB::connection(PlatformCatalog::CONNECTION)->table('plans as p')->join('plan_limits as l', 'l.plan_id', '=', 'p.id')
            ->whereIn('l.metric', ['api_rate_per_min', 'api_calls_month', 'api_keys', 'webhooks'])->orderBy('p.rank')->get(['p.id', 'p.name', 'l.metric', 'l.value'])
            ->groupBy('id')->map(fn ($rows) => ['plan' => $rows->first()->name] + $rows->mapWithKeys(fn ($r) => [$r->metric => $r->value === null ? null : (int) $r->value])->all())->values()->all();
        $rate = $limits['api_rate_per_min']['value'];

        return ['rate_per_min' => $rate, 'burst_per_sec' => $rate ? (int) ceil($rate / 6) : null, 'quota_month' => $limits['api_calls_month']['value'], 'used_month' => $used,
            'pct' => LimitService::pct($used, $limits['api_calls_month']['value']), 'plans' => $plans, 'keys' => $this->list(),
            'headers' => ['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset', 'X-Quota-Limit', 'X-Quota-Remaining', 'Retry-After']];
    }

    // ───────────────────────────── Autenticación (guard api-key) ─────────────────────────────

    /** Registro de la key por prefijo (conexión de plataforma, sin contexto de tenant). Null si no existe. */
    public static function lookup(string $token): ?object
    {
        if (! preg_match('/^qts_(live|test)_[A-Za-z0-9]{32}$/', $token)) {
            return null;
        }
        $prefix = substr($token, 0, 13);
        $row = Cache::remember(self::cacheKey($prefix), 60, fn () => (array) (DB::connection(PlatformCatalog::CONNECTION)->table('integration_api_keys')->where('prefix', $prefix)
            ->first(['id', 'tenant_id', 'code', 'token_hash', 'scopes', 'ip_allowlist', 'status', 'expires_at', 'created_by']) ?? ['missing' => true]));
        if (isset($row['missing']) || ! hash_equals($row['token_hash'], hash('sha256', $token))) {
            return null;
        }

        return (object) $row;
    }

    public static function ipAllowed(object $key, ?string $ip): bool
    {
        $list = json_decode((string) $key->ip_allowlist, true) ?: [];

        return $list === [] || ($ip !== null && IpUtils::checkIp($ip, $list));
    }

    public static function cacheKey(string $prefix): string
    {
        return "apikey:{$prefix}";
    }

    public static function counterKey(string $keyId, string $kind, Carbon $day): string
    {
        return "apikey:{$keyId}:{$kind}:{$day->format('Ymd')}";
    }

    private static function validCidr(string $value): bool
    {
        [$ip, $mask] = array_pad(explode('/', $value, 2), 2, null);
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        $max = str_contains($ip, ':') ? 128 : 32;

        return $mask === null || (ctype_digit($mask) && (int) $mask <= $max);
    }

    private static function base62(int $length): string
    {
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, 61)];
        }

        return $out;
    }
}
