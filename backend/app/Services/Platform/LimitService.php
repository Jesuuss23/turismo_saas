<?php

namespace App\Services\Platform;

use App\Exceptions\ApiException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Límites efectivos (plan + add-ons) y consumo frente a ellos (spec 13 §3.3, SUB-BR-04/05).
 * Los gauges (usuarios, sedes, almacenamiento, API keys, webhooks) se leen en vivo; los acumuladores, del medidor del mes.
 */
class LimitService
{
    public function __construct(private readonly UsageMeter $meter) {}

    /** @return array<string, array{value: ?int, hard: bool, plan: ?int, addon: int}> */
    public function limits(string $tenantId, ?string $planId = null): array
    {
        $db = self::db();
        $planId ??= $db->table('tenants')->where('id', $tenantId)->value('plan_id') ?? 'professional';
        $rows = $db->table('plan_limits')->where('plan_id', $planId)->get()->keyBy('metric');
        $extra = $this->addonGrants($tenantId);
        $out = [];
        foreach (array_keys(PlatformCatalog::METRICS) as $metric) {
            $row = $rows[$metric] ?? null;
            $plan = $row?->value === null ? null : (int) $row->value;
            $addon = $extra[$metric] ?? 0;
            $out[$metric] = ['value' => $row === null ? 0 : ($plan === null ? null : $plan + $addon), 'hard' => (bool) ($row?->hard ?? true), 'plan' => $plan, 'addon' => $addon];
        }

        return $out;
    }

    /** Unidades que suman los add-ons vigentes (los de retiro programado rigen hasta fin de periodo). */
    public function addonGrants(string $tenantId): array
    {
        $db = self::db();
        $sub = $db->table('subscriptions')->where('tenant_id', $tenantId)->where('status', '<>', 'canceled')->first();
        if (! $sub) {
            return [];
        }
        $today = PlatformClock::today()->toDateString();
        $grants = [];
        $items = $db->table('subscription_items as i')->join('addons as a', 'a.code', '=', 'i.addon_code')->where('i.subscription_id', $sub->id)
            ->where(fn ($q) => $q->whereNull('i.ends_at')->orWhere('i.ends_at', '>=', $today))->get(['i.quantity', 'a.grants']);
        foreach ($items as $item) {
            foreach (json_decode($item->grants, true) as $metric => $units) {
                if (isset(PlatformCatalog::METRICS[$metric])) {
                    $grants[$metric] = ($grants[$metric] ?? 0) + (int) $units * (int) $item->quantity;
                }
            }
        }

        return $grants;
    }

    /** @return array{users: int, sedes: int, storage_gb: float, api_keys: int, webhooks: int} */
    public function gauges(string $tenantId): array
    {
        $g = self::db()->selectOne('SELECT * FROM platform_usage_gauges(?)', [$tenantId]);

        return ['users' => (int) ($g->users ?? 0), 'sedes' => (int) ($g->sedes ?? 0), 'storage_gb' => round(((int) ($g->storage_bytes ?? 0)) / 1024 ** 3, 1),
            'api_keys' => (int) ($g->api_keys ?? 0), 'webhooks' => (int) ($g->webhooks ?? 0)];
    }

    public function used(string $tenantId, string $metric): float
    {
        return in_array($metric, ['users', 'sedes', 'storage_gb', 'api_keys', 'webhooks'], true)
            ? $this->gauges($tenantId)[$metric]
            : $this->meter->liveMonth($tenantId, $metric);
    }

    /**
     * Medidores del mes (actual: en vivo; pasados: `usage_monthly`), con nivel ok / aviso 80 % / tope 100 %.
     *
     * @return list<array{metric: string, label: string, unit: string, used: float, limit: ?int, pct: ?float, level: string, hard: bool, addon: int}>
     */
    public function usage(string $tenantId, ?string $month = null): array
    {
        $limits = $this->limits($tenantId);
        $current = $month === null || $month === PlatformClock::today()->format('Y-m');
        $gauges = $current ? $this->gauges($tenantId) : [];
        $stored = $current ? collect() : self::db()->table('usage_monthly')->where('tenant_id', $tenantId)->where('month', "{$month}-01")->get()->keyBy('metric');
        $out = [];
        foreach (PlatformCatalog::METERED as $metric) {
            [$label, $unit] = PlatformCatalog::METRICS[$metric];
            $used = $current ? ($gauges[$metric] ?? $this->meter->liveMonth($tenantId, $metric)) : (float) ($stored[$metric]->value ?? 0);
            $limit = $current ? $limits[$metric]['value'] : (isset($stored[$metric]) && $stored[$metric]->limit_value !== null ? (int) $stored[$metric]->limit_value : $limits[$metric]['value']);
            $out[] = ['metric' => $metric, 'label' => $label, 'unit' => $unit, 'used' => $used, 'limit' => $limit, 'pct' => self::pct($used, $limit),
                'level' => self::level($used, $limit), 'hard' => $limits[$metric]['hard'], 'addon' => $limits[$metric]['addon']];
        }

        return $out;
    }

    /** SUB-BR-04: límite hard al 100 % → 422 PLAN_LIMIT_REACHED. `$rule` = código de la regla del módulo que llama. */
    public function assertWithin(string $tenantId, string $metric, int $adding = 1, ?string $rule = null): void
    {
        $limit = $this->limits($tenantId)[$metric];
        if ($limit['value'] === null || ! $limit['hard']) {
            return;
        }
        $used = $this->used($tenantId, $metric);
        if ($used + $adding > $limit['value']) {
            $label = mb_strtolower(PlatformCatalog::metricLabel($metric));
            $message = $limit['value'] === 0
                ? "Su plan no incluye {$label}. Mejore su plan para habilitarlo."
                : "Alcanzó el límite de {$label} de su plan ({$limit['value']}). Mejore su plan o contrate un add-on.";

            throw new ApiException(422, 'PLAN_LIMIT_REACHED', $rule ?? 'SUB-BR-04', $message, [], ['metric' => $metric, 'limit' => $limit['value'], 'used' => $used]);
        }
    }

    public static function pct(float $used, ?int $limit): ?float
    {
        return $limit === null ? null : ($limit === 0 ? ($used > 0 ? 100.0 : 0.0) : round($used / $limit * 100, 1));
    }

    public static function level(float $used, ?int $limit): string
    {
        $pct = self::pct($used, $limit);

        return match (true) {
            $pct === null => 'ok',
            $pct >= 100 => 'limit',
            $pct >= 80 => 'warn',
            default => 'ok',
        };
    }

    private static function db(): ConnectionInterface
    {
        return DB::connection(PlatformCatalog::CONNECTION);
    }
}
