<?php

namespace App\Services\Platform;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Medición de uso (spec 13 §8.3): INCRBY en Redis `usage:{t}:{metric}:{yyyymmdd}` en el punto de consumo; `usage:consolidate`
 * lo vuelca a `usage_counters` (UPSERT idempotente). Si Redis falla se escribe en `usage_events` (degradación segura).
 */
class UsageMeter
{
    /** Las claves diarias viven 3 días: la consolidación lee hoy y ayer. */
    public const KEY_TTL = 259200;

    public function increment(string $tenantId, string $metric, int $n = 1, ?Carbon $at = null): void
    {
        $day = ($at ?? PlatformClock::now())->copy()->setTimezone(PlatformClock::TZ);
        $key = self::dayKey($tenantId, $metric, $day);
        try {
            Cache::add($key, 0, self::KEY_TTL);
            Cache::increment($key, $n);
        } catch (Throwable $e) {
            Log::warning('UsageMeter: Redis no disponible, se usa usage_events', ['metric' => $metric, 'error' => $e->getMessage()]);
            DB::table('usage_events')->insert(['tenant_id' => $tenantId, 'metric' => $metric, 'day' => $day->toDateString(), 'value' => $n, 'created_at' => now()]);
        }
    }

    /** Consumo del mes en curso: consolidado hasta ayer + contador de hoy (el mayor entre Redis y la fila ya consolidada). */
    public function liveMonth(string $tenantId, string $metric): int
    {
        $today = PlatformClock::today();
        $db = DB::connection(PlatformCatalog::CONNECTION);
        $base = (int) Cache::remember("usage:base:{$tenantId}:{$metric}:{$today->format('Ymd')}", 60, fn () => (int) $db->table('usage_counters')
            ->where('tenant_id', $tenantId)->where('metric', $metric)->where('day', '>=', $today->copy()->startOfMonth()->toDateString())
            ->where('day', '<', $today->toDateString())->sum('value'));
        $row = (int) $db->table('usage_counters')->where('tenant_id', $tenantId)->where('metric', $metric)->where('day', $today->toDateString())->value('value');

        return $base + max($row, $this->today($tenantId, $metric, $today));
    }

    public function today(string $tenantId, string $metric, ?Carbon $day = null): int
    {
        try {
            return (int) Cache::get(self::dayKey($tenantId, $metric, $day ?? PlatformClock::today()), 0);
        } catch (Throwable) {
            return 0;
        }
    }

    public static function dayKey(string $tenantId, string $metric, Carbon $day): string
    {
        return "usage:{$tenantId}:{$metric}:{$day->format('Ymd')}";
    }

    /** Invalida la base cacheada (consolidación o datos sembrados). */
    public static function forgetBase(string $tenantId, string $metric): void
    {
        Cache::forget("usage:base:{$tenantId}:{$metric}:".PlatformClock::today()->format('Ymd'));
    }
}
