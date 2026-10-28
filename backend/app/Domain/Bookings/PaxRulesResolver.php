<?php

namespace App\Domain\Bookings;

use App\Models\Catalog\PaxRule;
use App\Models\Catalog\Product;

/**
 * `PaxRulesResolver::resolve` (spec 16 §8.1): defaults del tenant (parámetros de R-CHILD-PER-ADULT, R-INFANT-FREE y
 * R-MIN-ADULTS, §12 #2) → `catalog_pax_rules` del producto → efectos de las reglas apagadas (§5.1).
 */
class PaxRulesResolver
{
    public function __construct(private readonly RuleResolver $rules) {}

    /** Defaults del tenant (sin producto). */
    public function defaults(): array
    {
        $child = $this->rules->params('R-CHILD-PER-ADULT');
        $infant = $this->rules->params('R-INFANT-FREE');
        $childMin = (int) ($child['childMinAge'] ?? 3);
        $childMax = (int) ($child['childMaxAge'] ?? 11);

        return [
            'adult_min_age' => $childMax + 1, 'child_min_age' => $childMin, 'child_max_age' => $childMax,
            'infant_max_age' => min((int) ($infant['infantMaxAge'] ?? 2), $childMin - 1),
            'child_pays' => true, 'child_price_pct' => (string) ($child['childPricePct'] ?? 75), 'infant_allowed' => true, 'infant_pays' => false,
            'max_children_per_adult' => (int) ($child['maxPerAdult'] ?? 2), 'max_infants_per_adult' => (int) ($infant['maxPerAdult'] ?? 1),
            'min_adults' => (int) $this->rules->param('R-MIN-ADULTS', 'min', null, 1), 'note' => null,
        ];
    }

    public function resolve(Product $product): PaxRules
    {
        $row = $product->relationLoaded('paxRule') ? $product->paxRule : PaxRule::where('product_id', $product->id)->first();
        $base = $row ? $row->only(array_keys($this->defaults())) : $this->defaults();
        if (! $row) {
            // Parámetros propios del producto en las reglas (override) también ajustan los defaults.
            $child = $this->rules->params('R-CHILD-PER-ADULT', $product);
            $infant = $this->rules->params('R-INFANT-FREE', $product);
            $base['max_children_per_adult'] = (int) ($child['maxPerAdult'] ?? $base['max_children_per_adult']);
            $base['child_price_pct'] = (string) ($child['childPricePct'] ?? $base['child_price_pct']);
            $base['max_infants_per_adult'] = (int) ($infant['maxPerAdult'] ?? $base['max_infants_per_adult']);
            $base['min_adults'] = (int) $this->rules->param('R-MIN-ADULTS', 'min', $product, $base['min_adults']);
        }
        $childOn = $this->rules->on('R-CHILD-PER-ADULT', $product);
        $infantOn = $this->rules->on('R-INFANT-FREE', $product);

        return new PaxRules(
            adultMinAge: (int) $base['adult_min_age'],
            childMinAge: (int) $base['child_min_age'],
            childMaxAge: (int) $base['child_max_age'],
            infantMaxAge: (int) $base['infant_max_age'],
            childPays: (bool) $base['child_pays'],
            childPricePct: $childOn ? (string) $base['child_price_pct'] : '100',
            infantAllowed: (bool) $base['infant_allowed'],
            infantPays: $infantOn ? (bool) $base['infant_pays'] : true,
            maxChildrenPerAdult: $childOn ? (int) $base['max_children_per_adult'] : null,
            maxInfantsPerAdult: $infantOn ? (int) $base['max_infants_per_adult'] : null,
            minAdults: $this->rules->on('R-MIN-ADULTS', $product) ? (int) $base['min_adults'] : 0,
            note: $base['note'] ?? null,
            source: $row ? 'producto' : 'tenant',
        );
    }
}
