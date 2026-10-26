<?php

namespace App\Services\Catalog;

use App\Exceptions\ApiException;
use App\Models\Catalog\ConfigOption;
use App\Models\Catalog\PriceRule;
use App\Models\Catalog\Product;
use App\Models\Catalog\Rate;
use App\Services\Crm\CrmClock;
use App\Services\PricingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * Motor de precios del catálogo (PROC-018, CAT-BR-41/42, CAT-BR-30). Único punto de cálculo:
 * el cliente nunca envía precios (PRD-BR-010, PRD-T15). Reutilizado por cotizador, B2B y e-commerce.
 */
class PricingEngine
{
    /** Canales cuyo markup es el de agencias (PR-02). */
    public const WHOLESALE_CHANNELS = ['B2B', 'Mayorista'];

    public function __construct(
        private readonly PricingService $pricing,
        private readonly SeasonService $seasons,
        private readonly CrmClock $clock,
    ) {}

    /** Reglas activas aplicables, por tipo. @return Collection<int, PriceRule> */
    public function rules(?Product $product = null): Collection
    {
        return PriceRule::where('status', 'activa')
            ->where(fn ($q) => $q->whereNull('product_id')->when($product, fn ($q) => $q->orWhere('product_id', $product->id)))
            ->orderBy('priority')->orderBy('ref')->get();
    }

    /**
     * Simulador CAT-BR-42: base = costo×pax + ajuste de temporada (costo×pax×(factor−1)); + adicionales; + markup;
     * − descuento; + impuesto; redondeo; comisión sobre neto; utilidad = neto − base − comisión.
     *
     * @param  array{date?: string, pax?: int, channel?: string, residency?: string, extra?: mixed, discount_fixed?: mixed, markup_override?: mixed, discount_override?: mixed, commission_pct?: mixed}  $in
     */
    public function simulate(Product $product, Rate $rate, array $in): array
    {
        $date = $in['date'] ?? $this->clock->today()->toDateString();
        $pax = max(1, (int) ($in['pax'] ?? 1));
        $channel = $in['channel'] ?? 'Directo';
        $residency = $in['residency'] ?? 'dom';
        $rules = $this->rules($product);
        $applied = [];

        $season = $this->seasons->resolve($date);
        $factor = $season ? BigDecimal::of((string) $season->factor) : BigDecimal::one();
        $cost = BigDecimal::of((string) $rate->cost)->multipliedBy($pax);
        $seasonAdj = $cost->multipliedBy($factor->minus(1));
        $base = $cost->plus($seasonAdj);

        $markupRule = $this->markupRule($rules, $channel, $product);
        $markupPct = $in['markup_override'] ?? null;
        $markupFixed = '0';
        if ($markupPct === null || $markupPct === '') {
            $markupPct = $markupRule && $markupRule->kind === 'Markup %' ? (string) $markupRule->value : '0';
            $markupFixed = $markupRule && $markupRule->kind === 'Markup fijo' ? (string) $markupRule->value : '0';
            if ($markupRule) {
                $applied[] = $this->ruleRow($markupRule);
            }
        } else {
            $applied[] = ['ref' => null, 'name' => 'Markup manual', 'kind' => 'Markup %', 'value' => (string) $markupPct];
        }

        $discountPct = $in['discount_override'] ?? null;
        if ($discountPct === null || $discountPct === '') {
            $groupRule = $rules->first(fn (PriceRule $r) => $r->kind === 'Descuento %' && $r->min_pax !== null && $pax >= $r->min_pax && $this->channelMatches($r, $channel));
            $discountPct = $groupRule ? (string) $groupRule->value : '0';
            if ($groupRule) {
                $applied[] = $this->ruleRow($groupRule);
            }
        }

        $taxRule = $rules->first(fn (PriceRule $r) => $r->kind === 'Impuesto %' && ($r->customer_residency === null || $r->customer_residency === $residency));
        $roundRule = $rules->firstWhere('kind', 'Redondeo');
        foreach ([$taxRule, $roundRule] as $rule) {
            if ($rule) {
                $applied[] = $this->ruleRow($rule);
            }
        }

        $commissionPct = $in['commission_pct'] ?? null;
        if ($commissionPct === null || $commissionPct === '') {
            $commissionRule = $rules->firstWhere('kind', 'Comisión %');
            $commissionPct = $commissionRule ? (string) $commissionRule->value : '0';
            if ($commissionRule) {
                $applied[] = $this->ruleRow($commissionRule);
            }
        }

        $calc = $this->pricing->calc([
            'cost' => (string) $base,
            'extra' => (string) ($in['extra'] ?? 0),
            'markupPct' => (string) $markupPct,
            'markupFixed' => $markupFixed,
            'discountPct' => (string) $discountPct,
            'discountFixed' => (string) ($in['discount_fixed'] ?? 0),
            'taxPct' => $taxRule ? (string) $taxRule->value : '0',
            'rounding' => $roundRule ? (string) $roundRule->value : '0',
            'commissionPct' => (string) $commissionPct,
        ]);

        // PR-08: precio mínimo por producto/moneda (el backend lo impone).
        $minRule = $rules->first(fn (PriceRule $r) => $r->kind === 'Precio mínimo' && ($r->currency === null || $r->currency === $rate->currency));
        $minimum = null;
        if ($minRule) {
            $minimum = BigDecimal::of((string) $minRule->value)->multipliedBy($pax);
            if (BigDecimal::of($calc['total'])->isLessThan($minimum)) {
                $calc['total'] = (string) $minimum->toScale(2, RoundingMode::HalfUp);
                $applied[] = $this->ruleRow($minRule);
            }
        }

        return [
            'product' => $product->ref,
            'rate' => $rate->ref,
            'currency' => $rate->currency,
            'date' => $date,
            'pax' => $pax,
            'channel' => $channel,
            'residency' => $residency,
            'season' => $season ? ['ref' => $season->ref, 'name' => $season->name, 'factor' => (string) $season->factor] : null,
            'steps' => [
                'cost' => self::money($cost),
                'season_adjustment' => self::money($seasonAdj),
                'base' => self::money($base),
                'extra' => self::money(BigDecimal::of((string) ($in['extra'] ?? 0))),
                'markup_pct' => (string) $markupPct,
                'markup' => $calc['markup'],
                'subtotal' => $calc['subtotal'],
                'discount_pct' => (string) $discountPct,
                'discount' => $calc['discount'],
                'net' => $calc['net'],
                'tax' => $calc['tax'],
                'total' => $calc['total'],
                'commission_pct' => (string) $commissionPct,
                'commission' => $calc['commission'],
                'profit' => $calc['profit'],
                'margin_pct' => $calc['marginPct'],
                'minimum' => $minimum ? self::money($minimum) : null,
            ],
            'rules' => $applied,
        ];
    }

    /**
     * CAT-BR-41: utilidad = venta − costo proveedor − otros costos − comisión (por pax × n); margen = utilidad / venta.
     */
    public function profitability(Rate $rate, int $pax, float $otherCost, float $commissionPct): array
    {
        $pax = max(1, $pax);
        $sale = BigDecimal::of((string) $rate->price)->multipliedBy($pax);
        $cost = BigDecimal::of((string) $rate->cost)->multipliedBy($pax);
        $other = BigDecimal::of((string) $otherCost)->multipliedBy($pax);
        $commission = $sale->multipliedBy((string) $commissionPct)->dividedBy(100, 10, RoundingMode::HalfUp);
        $profit = $sale->minus($cost)->minus($other)->minus($commission);

        return [
            'rate' => $rate->ref,
            'currency' => $rate->currency,
            'pax' => $pax,
            'sale' => self::money($sale),
            'provider_cost' => self::money($cost),
            'other_cost' => self::money($other),
            'commission_pct' => (string) $commissionPct,
            'commission' => self::money($commission),
            'profit' => self::money($profit),
            'margin_pct' => self::pct($profit, $sale),
        ];
    }

    /** Margen unitario de una tarifa: (precio − costo) / precio. */
    public static function marginPct(Rate $rate): string
    {
        $price = BigDecimal::of((string) $rate->price);

        return self::pct($price->minus((string) $rate->cost), $price);
    }

    /**
     * CAT-BR-30 / PRD-T15: precio de personalización = tarifa vigente + Σ deltas de las opciones elegidas,
     * siempre recalculado aquí (se ignora cualquier precio enviado por el cliente).
     *
     * @param  array<string, string>  $selections  group_id => option_id
     */
    public function priceQuote(Product $product, Rate $rate, array $selections, string $date, int $pax): array
    {
        $today = $this->clock->today()->toDateString();
        if ($rate->status === 'inactiva' || $rate->valid_to->toDateString() < $today || $date < $rate->valid_from->toDateString() || $date > $rate->valid_to->toDateString()) {
            throw ApiException::unprocessable('PRD-BR-005', "La tarifa {$rate->ref} no es aplicable en la fecha {$date} (vencida, inactiva o fuera de vigencia).");
        }
        $groups = $product->configGroups()->with('options')->get()->keyBy('id');
        $lines = [];
        $delta = BigDecimal::zero();
        foreach ($selections as $groupId => $optionId) {
            $group = $groups[$groupId] ?? throw ApiException::invalid("selections.{$groupId}", 'Grupo de personalización inexistente.');
            /** @var ConfigOption|null $option */
            $option = $group->options->firstWhere('id', $optionId) ?? throw ApiException::invalid("selections.{$groupId}", 'Opción inexistente en el grupo.');
            $delta = $delta->plus((string) $option->price_delta);
            $lines[] = ['group' => $group->name, 'option' => $option->label, 'price_delta' => self::money(BigDecimal::of((string) $option->price_delta))];
        }
        $unit = BigDecimal::of((string) $rate->price)->plus($delta);
        $pax = max(1, $pax);

        return [
            'rate' => $rate->ref,
            'currency' => $rate->currency,
            'base_price' => self::money(BigDecimal::of((string) $rate->price)),
            'options' => $lines,
            'unit_price' => self::money($unit),
            'pax' => $pax,
            'total' => self::money($unit->multipliedBy($pax)),
            'calculated_by' => 'backend',
        ];
    }

    private function markupRule(Collection $rules, string $channel, Product $product): ?PriceRule
    {
        $markups = $rules->filter(fn (PriceRule $r) => in_array($r->kind, ['Markup %', 'Markup fijo'], true));
        $specific = $markups->first(fn (PriceRule $r) => $r->product_id === $product->id && $this->channelMatches($r, $channel));
        if ($specific) {
            return $specific;
        }
        $target = in_array($channel, self::WHOLESALE_CHANNELS, true) ? 'B2B' : 'Directo';

        return $markups->first(fn (PriceRule $r) => $r->product_id === null && $r->channel === $target);
    }

    private function channelMatches(PriceRule $rule, string $channel): bool
    {
        return $rule->channel === null || $rule->channel === 'Todos' || $rule->channel === $channel
            || ($rule->channel === 'Directo' && $channel === 'E-commerce');
    }

    private function ruleRow(PriceRule $r): array
    {
        return ['ref' => $r->ref, 'name' => $r->name, 'kind' => $r->kind, 'value' => rtrim(rtrim((string) $r->value, '0'), '.')];
    }

    private static function money(BigDecimal $v): string
    {
        return (string) $v->toScale(2, RoundingMode::HalfUp);
    }

    private static function pct(BigDecimal $part, BigDecimal $whole): string
    {
        return $whole->isZero() ? '0.00' : (string) $part->multipliedBy(100)->dividedBy($whole, 2, RoundingMode::HalfUp);
    }
}
