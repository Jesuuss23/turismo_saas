<?php

namespace App\Services\Platform;

use Brick\Math\BigDecimal;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Métricas de la plataforma (spec 13 §8.2 `platform:metrics-snapshot`, `usage:consolidate`) y uso agregado por tenant.
 * El Super Admin solo ve contadores y montos de la suscripción, nunca datos comerciales de los tenants.
 */
class MetricsService
{
    public function __construct(
        private readonly LimitService $limits,
        private readonly UsageMeter $meter,
        private readonly CouponService $coupons,
        private readonly PlatformEvents $events,
    ) {}

    /** MRR por suscripción: precio mensualizado + add-ons − cupón. */
    public function mrrBySubscription(): array
    {
        $db = self::db();
        $out = [];
        foreach ($db->table('subscriptions')->whereIn('status', ['active', 'past_due', 'cancel_scheduled'])->get() as $sub) {
            $base = BigDecimal::of(BillingMath::monthly(BillingMath::price($sub), $sub->cycle));
            $items = $db->table('subscription_items')->where('subscription_id', $sub->id)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', PlatformClock::today()->toDateString()))->get();
            foreach ($items as $item) {
                $base = $base->plus(BigDecimal::of((string) $item->unit_price)->multipliedBy((int) $item->quantity));
            }
            if ($coupon = $this->coupons->active($sub)) {
                $base = $base->minus(CouponService::discount($coupon, (string) $base));
            }
            $out[$sub->tenant_id] = ['plan_id' => $sub->plan_id, 'mrr' => (float) (string) $base->toScale(2)];
        }

        return $out;
    }

    /** `platform:metrics-snapshot`: MRR, ARR, ARPA, altas, bajas y churn del día en `platform_metrics_daily` (UPSERT). */
    public function snapshot(?Carbon $day = null): array
    {
        $day ??= PlatformClock::today();
        $db = self::db();
        $mrrs = $this->mrrBySubscription();
        $mrr = array_sum(array_column($mrrs, 'mrr'));
        $paying = count($mrrs);
        $byPlan = [];
        foreach ($mrrs as $row) {
            $byPlan[$row['plan_id']] = round(($byPlan[$row['plan_id']] ?? 0) + $row['mrr'], 2);
        }
        $from90 = $day->copy()->subDays(90)->startOfDay()->utc();
        $churned90 = $db->table('platform_events')->where('kind', 'churn')->where('created_at', '>=', $from90)->count();
        $churnMrr = abs((float) $db->table('platform_events')->where('kind', 'churn')->where('created_at', '>=', $from90)->sum('mrr_delta'));
        $old = $db->table('platform_metrics_daily')->where('day', '<=', $day->copy()->subDays(90)->toDateString())->orderByDesc('day')->first();
        $base = $old ? (int) $old->active_tenants : max(1, $paying + $churned90);
        $baseMrr = $old ? (float) $old->mrr : max(1, $mrr + $churnMrr);
        $dayStart = $day->copy()->startOfDay()->utc();
        $dayEnd = $day->copy()->endOfDay()->utc();
        $row = [
            'day' => $day->toDateString(), 'mrr' => round($mrr, 2), 'arr' => round($mrr * 12, 2), 'arpa' => $paying ? round($mrr / $paying, 2) : 0,
            'active_tenants' => $paying, 'trialing_tenants' => $db->table('subscriptions')->where('status', 'trialing')->count(),
            'new_tenants' => $db->table('platform_events')->where('kind', 'new')->whereBetween('created_at', [$dayStart, $dayEnd])->count(),
            'churned_tenants' => $db->table('platform_events')->where('kind', 'churn')->whereBetween('created_at', [$dayStart, $dayEnd])->count(),
            'logo_churn_pct' => round($churned90 / max(1, $base) * 100, 2), 'revenue_churn_pct' => round($churnMrr / max(1, $baseMrr) * 100, 2),
            'failed_payments' => $db->table('platform_invoices')->where('status', 'failed')->count(), 'mrr_by_plan' => json_encode($byPlan),
        ];
        $db->table('platform_metrics_daily')->upsert([$row], ['day']);

        return $row;
    }

    /** Panel `plataforma/facturacion`: KPIs, evolución de MRR (cierre de cada mes), altas/bajas, MRR por plan y eventos recientes. */
    public function dashboard(?string $from = null, ?string $to = null): array
    {
        $db = self::db();
        $today = PlatformClock::today();
        $to ??= $today->toDateString();
        $from ??= $today->copy()->startOfMonth()->subMonthsNoOverflow(5)->toDateString();
        // El snapshot del día se recalcula al consultar; el job diario de la 01:00 deja el del día anterior cerrado.
        $live = $this->snapshot();
        $series = $db->table('platform_metrics_daily')->whereBetween('day', [$from, $to])->orderBy('day')->get()
            ->groupBy(fn ($r) => substr($r->day, 0, 7))->map(fn ($rows, $month) => ['month' => $month, 'mrr' => (float) $rows->last()->mrr, 'arr' => (float) $rows->last()->arr,
                'new' => (int) $rows->sum('new_tenants'), 'churned' => (int) $rows->sum('churned_tenants')])->values()->all();
        $plans = $db->table('plans')->pluck('name', 'id');

        return [
            'kpis' => ['mrr' => $live['mrr'], 'arr' => $live['arr'], 'arpa' => $live['arpa'], 'active_tenants' => $live['active_tenants'], 'trialing_tenants' => $live['trialing_tenants'],
                'logo_churn_pct' => $live['logo_churn_pct'], 'revenue_churn_pct' => $live['revenue_churn_pct'], 'failed_payments' => $live['failed_payments'],
                'failed_amount' => (float) $db->table('platform_invoices')->where('status', 'failed')->sum('total')],
            'series' => $series,
            'mrr_by_plan' => collect(json_decode($live['mrr_by_plan'], true))->map(fn ($v, $k) => ['plan_id' => $k, 'plan' => $plans[$k] ?? $k, 'mrr' => $v])->values()->all(),
            'events' => $db->table('platform_events as e')->join('tenants as t', 't.id', '=', 'e.tenant_id')->orderByDesc('e.created_at')->limit(15)
                ->get(['e.*', 't.code'])->map(fn ($e) => ['kind' => $e->kind, 'tenant' => $e->code, 'description' => $e->description, 'mrr_delta' => (float) $e->mrr_delta,
                    'at' => $e->created_at])->all(),
            'failed' => $db->table('platform_invoices as i')->join('tenants as t', 't.id', '=', 'i.tenant_id')->where('i.status', 'failed')->orderBy('i.dunning_next_retry_at')
                ->get(['i.*', 't.code', 't.trade_name'])->map(fn ($i) => ['number' => sprintf('%s-%08d', $i->series, $i->number), 'tenant' => $i->code, 'name' => $i->trade_name,
                    'total' => (float) $i->total, 'next_retry_at' => $i->dunning_next_retry_at, 'retries_done' => (int) $i->dunning_retries_done])->all(),
            'from' => $from, 'to' => $to,
        ];
    }

    /**
     * `usage:consolidate`: vuelca los contadores diarios de Redis (hoy y ayer) a `usage_counters` (UPSERT idempotente), suma el fallback
     * `usage_events`, recalcula `usage_monthly` y envía los avisos de 80/100 % una sola vez por mes (SUB-BR-05).
     */
    public function consolidate(?Carbon $today = null): array
    {
        $today ??= PlatformClock::today();
        $db = self::db();
        $counters = ['reservations_month', 'whatsapp_month', 'email_month', 'api_calls_month'];
        $days = [$today->copy()->subDay(), $today->copy()];
        $alerts = 0;
        foreach ($db->table('tenants')->pluck('id') as $tenantId) {
            foreach ($counters as $metric) {
                foreach ($days as $day) {
                    $redis = $this->meter->today($tenantId, $metric, $day);
                    $existing = (int) $db->table('usage_counters')->where('tenant_id', $tenantId)->where('metric', $metric)->where('day', $day->toDateString())->value('value');
                    $value = max($redis, $existing);
                    if ($value > 0) {
                        $db->table('usage_counters')->upsert([['tenant_id' => $tenantId, 'metric' => $metric, 'day' => $day->toDateString(), 'value' => $value]],
                            ['tenant_id', 'metric', 'day'], ['value']);
                    }
                }
                UsageMeter::forgetBase($tenantId, $metric);
            }
        }
        // Fallback: eventos escritos mientras Redis no estaba disponible.
        foreach ($db->table('usage_events')->selectRaw('tenant_id, metric, day, sum(value) as v, max(id) as last')->groupBy('tenant_id', 'metric', 'day')->get() as $e) {
            $db->statement('INSERT INTO usage_counters (tenant_id, metric, day, value) VALUES (?, ?, ?, ?) ON CONFLICT (tenant_id, metric, day) DO UPDATE SET value = usage_counters.value + EXCLUDED.value',
                [$e->tenant_id, $e->metric, $e->day, (int) $e->v]);
            $db->table('usage_events')->where('tenant_id', $e->tenant_id)->where('metric', $e->metric)->where('day', $e->day)->where('id', '<=', $e->last)->delete();
            UsageMeter::forgetBase($e->tenant_id, $e->metric);
        }
        $month = $today->copy()->startOfMonth();
        foreach ($db->table('tenants')->get(['id', 'code']) as $tenant) {
            foreach ($this->limits->usage($tenant->id) as $m) {
                $key = ['tenant_id' => $tenant->id, 'metric' => $m['metric'], 'month' => $month->toDateString()];
                $row = $db->table('usage_monthly')->where($key)->first();
                $db->table('usage_monthly')->upsert([$key + ['value' => (int) round($m['used']), 'limit_value' => $m['limit']]], ['tenant_id', 'metric', 'month'], ['value', 'limit_value']);
                if ($m['limit'] === null || $m['limit'] === 0) {
                    continue;
                }
                foreach ([100 => 'alert_100_sent_at', 80 => 'alert_80_sent_at'] as $threshold => $column) {
                    if ($m['pct'] >= $threshold && ! ($row->{$column} ?? null)) {
                        $db->table('usage_monthly')->where($key)->update([$column => now()]);
                        $this->events->notifyTenant($tenant->id, "uso.aviso_{$threshold}", "{$m['label']}: {$m['pct']} % del límite de su plan",
                            "Lleva {$m['used']} de {$m['limit']} {$m['unit']} este mes. Mejore su plan o contrate un add-on para evitar bloqueos.");
                        $alerts++;
                        break;
                    }
                }
            }
        }
        // Claves de Redis de más de 2 días.
        foreach ($db->table('tenants')->pluck('id') as $tenantId) {
            foreach ($counters as $metric) {
                Cache::forget(UsageMeter::dayKey($tenantId, $metric, $today->copy()->subDays(2)));
            }
        }

        return ['alerts' => $alerts];
    }

    /** Uso agregado por tenant (solo contadores) con el % del límite más consumido. */
    public function usageByTenant(?string $plan = null): array
    {
        $db = self::db();
        $plans = $db->table('plans')->pluck('name', 'id');

        return $db->table('tenants')->when($plan, fn ($q) => $q->where('plan_id', $plan))->orderBy('code')->get(['id', 'code', 'trade_name', 'legal_name', 'plan_id', 'status'])
            ->map(function ($t) use ($plans) {
                $usage = collect($this->limits->usage($t->id));
                $top = $usage->filter(fn ($m) => $m['pct'] !== null && $m['limit'] !== 0)->sortByDesc('pct')->first();

                return ['tenant_id' => $t->id, 'code' => $t->code, 'name' => $t->trade_name ?? $t->legal_name, 'plan_id' => $t->plan_id, 'plan' => $plans[$t->plan_id] ?? $t->plan_id,
                    'status' => $t->status, 'metrics' => $usage->mapWithKeys(fn ($m) => [$m['metric'] => ['used' => $m['used'], 'limit' => $m['limit'], 'pct' => $m['pct']]])->all(),
                    'top' => $top ? ['metric' => $top['metric'], 'label' => $top['label'], 'pct' => $top['pct'], 'level' => $top['level']] : null];
            })->all();
    }

    private static function db(): ConnectionInterface
    {
        return DB::connection(PlatformCatalog::CONNECTION);
    }
}
