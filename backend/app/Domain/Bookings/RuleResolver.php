<?php

namespace App\Domain\Bookings;

use App\Models\Catalog\Product;
use App\Models\Catalog\RuleOverride;
use App\Models\Sys\BookingRule;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

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

    public function __construct(private readonly TenantContext $tenant) {}

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

    /** @return array{rules: array<string, array>, overrides: array<string, array<string, array>>} */
    public function data(): array
    {
        $key = 'rules:'.$this->tenant->id().':v'.$this->version();
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
                    'schema' => (array) $r->params_schema,
                ];
            }
            $overrides = [];
            foreach (RuleOverride::get() as $o) {
                $overrides[$o->product_id][$o->rule_key] = ['enabled' => $o->enabled, 'params' => (array) $o->params];
            }

            return ['rules' => $rules, 'overrides' => $overrides];
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
        if (! $rule || ! $rule['enabled']) {
            return false;
        }
        if (! $rule['is_system'] && ! RuleRegistry::has($key)) {
            return false;
        }
        $override = $rule['product_override'] ? $this->override($key, $product) : null;

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
     * Estado de una regla para la UI: on | off_tenant | off_product.
     */
    public function state(string $key, Product|string|null $product = null): string
    {
        if (! $this->tenantOn($key)) {
            return 'off_tenant';
        }

        return $this->on($key, $product) ? 'on' : 'off_product';
    }

    private function versionKey(): string
    {
        return 'rules:ver:'.$this->tenant->id();
    }
}
