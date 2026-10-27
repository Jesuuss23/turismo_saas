<?php

namespace App\Services\Platform;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Entitlements efectivos del tenant (spec 13 §7, ENT-BR-01): override vigente > add-on activo disponible en el plan > plan_features.
 * Se cachean en `ent:{tenant}` 10 minutos y se invalidan al cambiar plan, add-ons, overrides o la matriz. El frontend nunca es autoridad.
 */
class EntitlementService
{
    public const TTL = 600;

    /** @return array<string, array{key: string, label: string, module: string, kind: string, enabled: bool, value: string, origin: string, valid_until: ?string}> */
    public function for(string $tenantId): array
    {
        return Cache::remember(self::key($tenantId), self::TTL, fn () => $this->resolve($tenantId));
    }

    public function enabled(string $tenantId, string $feature): bool
    {
        return (bool) ($this->for($tenantId)[$feature]['enabled'] ?? false);
    }

    public static function forget(string $tenantId): void
    {
        Cache::forget(self::key($tenantId));
    }

    /** Invalida todos los tenants (cambio de la matriz por plan). */
    public static function forgetAll(): void
    {
        foreach (self::db()->table('tenants')->pluck('id') as $id) {
            self::forget($id);
        }
    }

    public static function key(string $tenantId): string
    {
        return "ent:{$tenantId}";
    }

    private function resolve(string $tenantId): array
    {
        $db = self::db();
        $planId = $db->table('tenants')->where('id', $tenantId)->value('plan_id') ?? 'professional';
        $today = PlatformClock::today()->toDateString();
        $plan = $db->table('plan_features')->where('plan_id', $planId)->pluck('value', 'feature_key');
        $overrides = $db->table('feature_overrides')->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today))->get()->keyBy('feature_key');
        $sub = $db->table('subscriptions')->where('tenant_id', $tenantId)->where('status', '<>', 'canceled')->first();
        $items = $sub ? $db->table('subscription_items')->where('subscription_id', $sub->id)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $today))->pluck('addon_code')->all() : [];
        $addons = $db->table('addons')->get()->keyBy('code');

        $out = [];
        foreach ($db->table('features')->orderBy('sort')->get() as $f) {
            $value = $plan[$f->key] ?? 'not_included';
            $enabled = $value !== 'not_included';
            $origin = $enabled ? 'plan' : '—';
            $validUntil = null;
            foreach ($addons as $code => $addon) {
                $grants = json_decode($addon->grants, true);
                if (($grants['feature'] ?? null) !== $f->key) {
                    continue;
                }
                $available = in_array($planId, json_decode($addon->available_plans, true), true);
                if (in_array($planId, json_decode($addon->included_plans, true), true)) {
                    [$enabled, $value, $origin] = [true, $grants['value'] ?? 'included', 'plan'];
                } elseif ($available && in_array($code, $items, true)) {
                    [$enabled, $value, $origin] = [true, $grants['value'] ?? 'included', 'addon'];
                }
            }
            if ($o = $overrides[$f->key] ?? null) {
                [$enabled, $value, $origin, $validUntil] = [(bool) $o->value, $o->value ? ($enabled ? $value : 'included') : 'not_included', 'override', $o->valid_until];
            }
            $out[$f->key] = ['key' => $f->key, 'label' => $f->label, 'module' => $f->module, 'kind' => $f->kind, 'enabled' => $enabled, 'value' => $value,
                'origin' => $origin, 'valid_until' => $validUntil];
        }

        return $out;
    }

    private static function db(): ConnectionInterface
    {
        return DB::connection(PlatformCatalog::CONNECTION);
    }
}
