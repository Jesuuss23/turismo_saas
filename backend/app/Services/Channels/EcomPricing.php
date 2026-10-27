<?php

namespace App\Services\Channels;

use App\Exceptions\ApiException;
use App\Models\Catalog\ConfigGroup;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductPromotion;
use App\Models\Catalog\Rate;
use App\Services\Crm\CrmClock;
use App\Services\Quotes\QuotePricing;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * Precio web (spec 08 ECO-BR-03/04/05, §11-11): tarifa del canal E-commerce con fallback a Directo; niño con tarifa propia o precio adulto;
 * deltas de personalización por persona; promociones no acumulables (mayor descuento), por código o automáticas por cantidad.
 */
class EcomPricing
{
    public const PROMO_MIN_PAX = 4;

    public function __construct(private readonly QuotePricing $pricing, private readonly CrmClock $clock) {}

    /** @return array{0: ?Rate, 1: ?Rate} tarifa adulto y niño (o null) vigentes en la fecha */
    public function rates(Product $product, string $date): array
    {
        $valid = Rate::where('product_id', $product->id)->orderBy('ref')->get()->filter(fn (Rate $r) => QuotePricing::rateValidOn($r, $date) && ! $r->isNet())->values();
        $adultSeg = fn (Rate $r) => in_array($r->segment, ['Adulto', 'Pasajero'], true);
        $adult = $valid->first(fn (Rate $r) => $r->channel === 'E-commerce' && $adultSeg($r))
            ?? $valid->first(fn (Rate $r) => $r->channel === 'Directo' && $adultSeg($r))
            ?? $valid->first(fn (Rate $r) => $r->channel === 'Directo' && $r->segment !== 'Niño')
            ?? $valid->first(fn (Rate $r) => $r->segment !== 'Niño');
        $child = $valid->first(fn (Rate $r) => $r->segment === 'Niño' && $r->channel === 'E-commerce')
            ?? $valid->first(fn (Rate $r) => $r->segment === 'Niño' && $r->channel === 'Directo');

        return [$adult, $child];
    }

    /** ECO-BR-06: niños solo con tarifa niño o edad mínima < 12. */
    public function childrenAllowed(Product $product, ?Rate $child): bool
    {
        return $child !== null || $product->minimum_age < 12;
    }

    /** Promociones aplicables hoy en e-commerce (activa, vigente, canal E-commerce/Todos). @return Collection<int, ProductPromotion> */
    public function promotions(Product $product): Collection
    {
        $today = $this->clock->today()->toDateString();

        return ProductPromotion::where('product_id', $product->id)->where('status', 'activa')->whereIn('channel', ['E-commerce', 'Todos'])
            ->whereDate('date_from', '<=', $today)->whereDate('date_to', '>=', $today)->orderBy('ref')->get();
    }

    /**
     * @param  array{adults?: int, children?: int, options?: array, code?: ?string}  $data
     * @return array{currency: string, adult_unit: string, child_unit: string, delta_pp: string, subtotal: string, discount: string, discount_label: ?string,
     *               total: string, cost: string, promo_state: string, promotion_id: ?string, promo_code: ?string, list_price: ?string, rate_id: string, channel: string, options: list<array>}
     */
    public function quote(Product $product, string $date, array $data): array
    {
        [$adultRate, $childRate] = $this->rates($product, $date);
        if (! $adultRate) {
            throw ApiException::unprocessable('ECO-BR-02', 'El producto no tiene tarifa vigente para esa fecha.');
        }
        $adults = max(0, (int) ($data['adults'] ?? 1));
        $children = max(0, (int) ($data['children'] ?? 0));
        $currency = $adultRate->currency;
        $fx = $this->pricing->fx();
        $a = $this->pricing->suggest($adultRate, $date, $currency, $fx);
        $c = $childRate ? $this->pricing->suggest($childRate, $date, $currency, $fx) : $a;
        $options = $this->options($product, (array) ($data['options'] ?? []));
        $delta = $options->reduce(fn (BigDecimal $sum, array $o) => $sum->plus($o['delta_pp']), BigDecimal::zero());
        $pax = $adults + $children;
        $subtotal = BigDecimal::of($a['unit_price'])->plus($delta)->multipliedBy($adults)->plus(BigDecimal::of($c['unit_price'])->plus($delta)->multipliedBy($children))->toScale(2, RoundingMode::HalfUp);
        $cost = BigDecimal::of($a['unit_cost'])->multipliedBy($adults)->plus(BigDecimal::of($c['unit_cost'])->multipliedBy($children))->toScale(2, RoundingMode::HalfUp);

        // ECO-BR-05: código inválido bloquea; automática por cantidad; no acumulables → la de mayor descuento.
        $promos = $this->promotions($product);
        $code = mb_strtoupper(trim((string) ($data['code'] ?? '')));
        $state = 'none';
        $candidates = collect();
        if ($code !== '') {
            $byCode = $promos->first(fn (ProductPromotion $p) => $p->promo_code !== null && mb_strtoupper($p->promo_code) === $code);
            $state = $byCode ? 'ok' : 'bad';
            if ($byCode) {
                $candidates->push($byCode);
            }
        }
        $promos->filter(fn (ProductPromotion $p) => $p->promo_code === null && $p->kind === 'Descuento por cantidad' && $pax >= ($p->min_pax ?? self::PROMO_MIN_PAX))
            ->each(fn ($p) => $candidates->push($p));
        $best = null;
        $discount = BigDecimal::zero()->toScale(2);
        foreach ($candidates as $p) {
            $d = $this->discount($p, $subtotal);
            if ($d->isGreaterThan($discount)) {
                [$best, $discount] = [$p, $d];
            }
        }
        $public = Rate::where('product_id', $product->id)->where('channel', 'Directo')->whereIn('segment', ['Adulto', 'Pasajero'])->get()
            ->first(fn (Rate $r) => QuotePricing::rateValidOn($r, $date));
        $listPrice = $public && $public->id !== $adultRate->id ? $this->pricing->suggest($public, $date, $currency, $fx)['unit_price'] : null;

        return [
            'currency' => $currency, 'rate_id' => $adultRate->id, 'channel' => $adultRate->channel, 'adult_unit' => (string) $a['unit_price'], 'child_unit' => (string) $c['unit_price'],
            'child_rate' => (bool) $childRate, 'delta_pp' => (string) $delta->toScale(2), 'adults' => $adults, 'children' => $children,
            'subtotal' => (string) $subtotal, 'discount' => (string) $discount, 'discount_label' => $best ? "{$best->name} (".self::percent($best).')' : null,
            'total' => (string) $subtotal->minus($discount)->toScale(2, RoundingMode::HalfUp), 'cost' => (string) $cost,
            'promo_state' => $state, 'promotion_id' => $best?->id, 'promo_code' => $state === 'ok' ? $code : null,
            'list_price' => $listPrice !== null && (float) $listPrice > (float) $a['unit_price'] ? (string) $listPrice : null,
            'options' => $options->all(),
            'promo_message' => $state === 'bad' ? 'Código no válido para este producto o fuera de vigencia.' : null,
        ];
    }

    private function discount(ProductPromotion $p, BigDecimal $subtotal): BigDecimal
    {
        if (in_array($p->kind, ProductPromotion::PERCENT_KINDS, true)) {
            return $subtotal->multipliedBy((string) $p->value)->dividedBy(100, 2, RoundingMode::HalfUp);
        }
        if ($p->kind === 'Descuento fijo') {
            $d = BigDecimal::of((string) $p->value)->toScale(2);

            return $d->isGreaterThan($subtotal) ? $subtotal : $d;
        }

        return BigDecimal::zero()->toScale(2);
    }

    public static function percent(ProductPromotion $p): string
    {
        return in_array($p->kind, ProductPromotion::PERCENT_KINDS, true) ? rtrim(rtrim((string) $p->value, '0'), '.').' %' : (string) $p->value;
    }

    /** ECO-BR-04: una opción por grupo; por defecto la de delta 0. @return Collection<int, array> */
    private function options(Product $product, array $chosen): Collection
    {
        $groups = ConfigGroup::where('product_id', $product->id)->with('options')->orderBy('sort_order')->get();

        return $groups->map(function (ConfigGroup $g) use ($chosen) {
            $wanted = $chosen[$g->id] ?? $chosen[$g->name] ?? null;
            $option = $g->options->first(fn ($o) => $o->id === $wanted || $o->label === $wanted)
                ?? $g->options->first(fn ($o) => (float) $o->price_delta === 0.0) ?? $g->options->first();

            return $option ? ['group_id' => $g->id, 'group' => $g->name, 'option_id' => $option->id, 'option' => $option->label, 'delta_pp' => (string) $option->price_delta] : null;
        })->filter()->values();
    }
}
