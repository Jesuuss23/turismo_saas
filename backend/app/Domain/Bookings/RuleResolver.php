<?php

namespace App\Domain\Bookings;

use App\Models\Catalog\Product;
use App\Models\Catalog\RuleOverride;
use App\Models\Sys\BookingRule;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * `BE.on(id, p)` / `BE.param(id, name, p)` (spec 16 §8.1): estado efectivo de una regla del centro para el tenant y,
 * si se pasa, para el producto. Un override solo puede apagar o reparametrizar una regla con `product_override`
 * (CAT-RL-01); nunca encender una apagada en el tenant (CAT-RL-02). Caché Redis `rules:{tenant}:v{ver}` (1 h) con
 * contador de versión para invalidar en O(1) (§8.3), más memo por instancia.
 */
class RuleResolver
{
    private ?array $memo = null;

    private ?string $memoKey = null;

    /** «Plan a simular» (spec 17 v2.2): sustituye el plan del tenant solo durante el cálculo. */
    private ?string $planOverride = null;

    public function __construct(private readonly TenantContext $tenant) {}

    /** Ejecuta `$fn` evaluando las reglas como si el tenant tuviera `$plan` (vista previa; no cambia la suscripción). */
    public function asPlan(?string $plan, callable $fn): mixed
    {
        $previous = $this->planOverride;
        $this->planOverride = $plan;
        try {
            return $fn();
        } finally {
            $this->planOverride = $previous;
        }
    }

    public function plan(): ?string
    {
        return $this->planOverride ?? $this->tenant->tenant()?->plan_id;
    }

    /** Matriz Planes × reglas cambiada en la plataforma: invalida las reglas de todos los tenants. */
    public static function bumpEntitlements(): void
    {
        Cache::increment('rules:entitlements:ver');
    }

    /** PLN-R-01: una regla fuera del plan no aplica. Las personalizadas del tenant no tienen plan mínimo. */
    public function planAllows(string $key, ?string $plan = null): bool
    {
        $min = $this->data()['entitlements'][$key] ?? null;
        if ($min === null) {
            return true;
        }
        $ranks = $this->data()['ranks'];

        return ($ranks[$plan ?? $this->plan()] ?? 0) >= ($ranks[$min] ?? PHP_INT_MAX);
    }

    public function minPlan(string $key): ?string
    {
        return $this->data()['entitlements'][$key] ?? null;
    }

    public function version(): int
    {
        return (int) Cache::get($this->versionKey(), 0);
    }

    /** BookingRuleChanged: invalida reglas y, con ellas, toda la disponibilidad cacheada del tenant. */
    public function bump(): void
    {
        Cache::increment($this->versionKey());
        $this->memo = null;
    }

    /** @return array{rules: array<string, array>, overrides: array<string, array<string, array>>, entitlements: array<string, string>, ranks: array<string, int>} */
    public function data(): array
    {
        $key = 'rules:'.$this->tenant->id().':v'.$this->version().':e'.(int) Cache::get('rules:entitlements:ver', 0);
        if ($this->memoKey === $key && $this->memo !== null) {
            return $this->memo;
        }
        $this->memoKey = $key;

        return $this->memo = Cache::remember($key, now()->addHour(), function () {
            $rules = [];
            foreach (BookingRule::orderBy('sort_order')->orderBy('rule_key')->get() as $r) {
                $rules[$r->rule_key] = [
                    'key' => $r->rule_key, 'group' => $r->group_name, 'name' => $r->name, 'description' => $r->description, 'enabled' => $r->enabled,
                    'params' => (array) $r->params, 'scope' => (array) $r->scope, 'product_override' => $r->product_override, 'is_system' => $r->is_system,
                    'schema' => (array) $r->params_schema, 'depends_on' => $r->depends_on,
                ];
            }
            $overrides = [];
            foreach (RuleOverride::get() as $o) {
                $overrides[$o->product_id][$o->rule_key] = ['enabled' => $o->enabled, 'params' => (array) $o->params];
            }

            return ['rules' => $rules, 'overrides' => $overrides, 'entitlements' => DB::table('plan_rule_entitlements')->pluck('min_plan', 'rule_code')->all(),
                'ranks' => DB::table('plans')->pluck('rank', 'id')->map(fn ($r) => (int) $r)->all()];
        });
    }

    /** @return array<string, array> */
    public function rules(): array
    {
        return $this->data()['rules'];
    }

    public function rule(string $key): ?array
    {
        return $this->rules()[$key] ?? null;
    }

    public function override(string $key, Product|string|null $product): ?array
    {
        $id = $product instanceof Product ? $product->id : $product;

        return $id ? ($this->data()['overrides'][$id][$key] ?? null) : null;
    }

    public function tenantOn(string $key): bool
    {
        return (bool) ($this->rule($key)['enabled'] ?? false);
    }

    public function on(string $key, Product|string|null $product = null): bool
    {
        $rule = $this->rule($key);
        if (! $rule || ! $rule['enabled'] || ! $this->planAllows($key)) {
            return false;
        }
        // Dependencia: R-LODGING-CAPACITY y R-SINGLE-SUPP no tienen efecto sin R-LODGING.
        if ($rule['depends_on'] && ! $this->on($rule['depends_on'], $product)) {
            return false;
        }
        $override = $rule['product_override'] ? $this->override($key, $product) : null;

        // CR-04: las reglas personalizadas son banderas consultables; su efecto requiere desarrollo (RuleRegistry).
        return ($override['enabled'] ?? null) !== false;
    }

    /** @return array<string, mixed> parámetros del tenant con los del producto encima. */
    public function params(string $key, Product|string|null $product = null): array
    {
        $rule = $this->rule($key);
        if (! $rule) {
            return [];
        }
        $override = $rule['product_override'] ? $this->override($key, $product) : null;

        return array_replace($rule['params'], $override['params'] ?? []);
    }

    public function param(string $key, string $name, Product|string|null $product = null, mixed $default = null): mixed
    {
        return $this->params($key, $product)[$name] ?? $default;
    }

    /**
     * Estado de una regla para la UI: on | off_tenant | off_plan | off_product.
     */
    public function state(string $key, Product|string|null $product = null): string
    {
        if (! $this->tenantOn($key)) {
            return 'off_tenant';
        }
        if (! $this->planAllows($key)) {
            return 'off_plan';
        }

        return $this->on($key, $product) ? 'on' : 'off_product';
    }

    private function versionKey(): string
    {
        return 'rules:ver:'.$this->tenant->id();
    }
}
