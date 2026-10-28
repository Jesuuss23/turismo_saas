<?php

namespace App\Services\Site;

use App\Exceptions\ApiException;
use App\Models\Catalog\Destination;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductPromotion;
use App\Models\Catalog\Rate;
use App\Models\Catalog\Season;
use App\Services\Catalog\SeasonService;
use App\Services\Channels\EcomPricing;
use App\Services\Crm\CrmClock;
use App\Services\Finance\FxRates;
use App\Services\Quotes\QuotePricing;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * Precio del sitio (spec 15 SIT-BR-02/03/08/10/11/12): tarifa del canal E-commerce (o Directo) vigente en la fecha × factor de la temporada de
 * la fecha ÷ factor de la temporada base de la tarifa, redondeado a 0.10; promoción por línea (código o grupo, no acumulables); extras y moneda.
 * Reutiliza las tarifas y promociones de la tienda de 08 (RT-18): el sitio no duplica reglas.
 */
class SitePricing
{
    use RequestMemo;

    public const INSURANCE_USD_PER_PAX_DAY = '6.00';

    public const CURRENCIES = ['PEN', 'USD'];

    public function __construct(
        private readonly EcomPricing $ecom,
        private readonly SeasonService $seasons,
        private readonly CrmClock $clock,
        private readonly FxRates $fx,
    ) {}

    /** Redondeo comercial a 0.10 (SIT-BR-02). */
    public static function round10(BigDecimal $value): BigDecimal
    {
        return $value->dividedBy('0.1', 0, RoundingMode::HalfUp)->multipliedBy('0.1')->toScale(2);
    }

    public function season(string $date): ?Season
    {
        return $this->memo("season:{$date}", fn () => $this->seasons->resolve($date));
    }

    /**
     * Precio unitario de una tarifa en una fecha (en la moneda de la tarifa). @return array{amount: string, cost: string, season: ?Season, factor: string}
     */
    public function rateOn(Rate $rate, string $date): array
    {
        $base = $rate->season_id ? Season::withTrashed()->find($rate->season_id) : null;
        $baseFactor = $base ? (string) $base->factor : '1';
        $season = $this->season($date);
        $factor = BigDecimal::of($season ? (string) $season->factor : '1')->dividedBy($baseFactor, 4, RoundingMode::HalfUp);

        return [
            'amount' => (string) self::round10(BigDecimal::of((string) $rate->price)->multipliedBy($factor)),
            'cost' => (string) BigDecimal::of((string) $rate->cost)->multipliedBy($factor)->toScale(2, RoundingMode::HalfUp),
            'season' => $season, 'factor' => (string) $factor,
        ];
    }

    /**
     * Precio adulto de un día (SIT-BR-02/03): null si no hay tarifa vigente ese día («Tarifa por publicar»).
     *
     * @return array{adult: string, child: string, currency: string, list_price: ?string, season: ?array, rate_id: string}|null
     */
    public function day(Product $product, string $date): ?array
    {
        [$adult, $child] = $this->ecom->rates($product, $date);
        if (! $adult) {
            return null;
        }
        $a = $this->rateOn($adult, $date);
        $c = $child ? $this->rateOn($child, $date) : $a;
        $public = $adult->channel !== 'Directo' ? Rate::where('product_id', $product->id)->where('channel', 'Directo')->whereIn('segment', ['Adulto', 'Pasajero'])->get()
            ->first(fn (Rate $r) => QuotePricing::rateValidOn($r, $date) && $r->currency === $adult->currency) : null;
        $list = $public ? $this->rateOn($public, $date)['amount'] : null;

        return ['adult' => $a['amount'], 'child' => $c['amount'], 'currency' => $adult->currency, 'rate_id' => $adult->id, 'channel' => $adult->channel,
            'list_price' => $list !== null && (float) $list > (float) $a['amount'] ? $list : null, 'season' => self::seasonRow($a['season']), 'factor' => $a['factor']];
    }

    public static function seasonRow(?Season $s): ?array
    {
        if (! $s) {
            return null;
        }
        $kind = match (true) {
            in_array($s->type, ['Alta', 'Feriado', 'Fecha especial'], true) && (float) $s->factor >= 1.1 => in_array($s->type, ['Feriado', 'Fecha especial'], true) ? 'especial' : 'alta',
            (float) $s->factor < 1 || $s->type === 'Baja' => 'baja',
            default => 'media',
        };

        return ['ref' => $s->ref, 'name' => $s->name, 'type' => $s->type, 'factor' => (string) $s->factor, 'kind' => $kind];
    }

    /**
     * Cotización de una línea (SIT-BR-02/05/08/10/11): montos en la moneda de la tarifa; los extras se convierten a esa moneda.
     *
     * @param  array{date: string, adults?: int, children?: int, infants?: int, options?: array, extras?: array}  $sel
     */
    public function line(Product $product, array $sel, ?string $promoCode = null): array
    {
        $date = (string) $sel['date'];
        [$adultRate, $childRate] = $this->ecom->rates($product, $date);
        if (! $adultRate) {
            throw ApiException::unprocessable('SIT-BR-02', 'Tarifa por publicar: el producto no tiene tarifa vigente para esa fecha.');
        }
        $adults = max(0, (int) ($sel['adults'] ?? 1));
        $children = max(0, (int) ($sel['children'] ?? 0));
        $infants = max(0, (int) ($sel['infants'] ?? 0));
        $currency = $adultRate->currency;
        $a = $this->rateOn($adultRate, $date);
        $c = $childRate ? $this->rateOn($childRate, $date) : $a;
        $options = $this->options($product, (array) ($sel['options'] ?? []));
        $delta = $options->reduce(fn (BigDecimal $sum, array $o) => $sum->plus($o['delta_pp']), BigDecimal::zero());
        $unitAdult = BigDecimal::of($a['amount'])->plus($delta)->toScale(2);
        $unitChild = BigDecimal::of($c['amount'])->plus($delta)->toScale(2);
        $pax = $adults + $children;
        $units = null;
        if (self::isTransfer($product)) {
            // SIT-BR-11: precio por vehículo de hasta max_pax pasajeros.
            $units = (int) max(1, ceil(($pax + $infants) / max(1, $product->maximum_passengers)));
            $subtotal = $unitAdult->multipliedBy($units)->toScale(2);
            $cost = BigDecimal::of($a['cost'])->multipliedBy($units)->toScale(2);
        } else {
            $subtotal = $unitAdult->multipliedBy($adults)->plus($unitChild->multipliedBy($children))->toScale(2);
            $cost = BigDecimal::of($a['cost'])->multipliedBy($adults)->plus(BigDecimal::of($c['cost'])->multipliedBy($children))->toScale(2, RoundingMode::HalfUp);
        }

        // SIT-BR-08: código del carrito si el producto lo tiene vigente; grupo 4+ automático; gana el mayor por línea.
        $promos = $this->ecom->promotions($product);
        $code = mb_strtoupper(trim((string) $promoCode));
        $candidates = collect();
        $codeApplies = false;
        if ($code !== '' && ($byCode = $promos->first(fn (ProductPromotion $p) => $p->promo_code !== null && mb_strtoupper($p->promo_code) === $code))) {
            $candidates->push($byCode);
            $codeApplies = true;
        }
        $promos->filter(fn (ProductPromotion $p) => $p->promo_code === null && $p->kind === 'Descuento por cantidad' && $pax >= ($p->min_pax ?? EcomPricing::PROMO_MIN_PAX))
            ->each(fn ($p) => $candidates->push($p));
        $best = null;
        $discount = BigDecimal::zero()->toScale(2);
        foreach ($candidates as $p) {
            $d = $this->discount($p, $subtotal);
            if ($d->isGreaterThan($discount)) {
                [$best, $discount] = [$p, $d];
            }
        }

        $extras = $this->extras($product, $sel['extras'] ?? [], $adults + $children + $infants, $date, $currency);
        $extrasTotal = array_reduce($extras, fn (BigDecimal $s, array $e) => $s->plus($e['amount_line']), BigDecimal::zero())->toScale(2);
        $extrasCost = array_reduce($extras, fn (BigDecimal $s, array $e) => $s->plus($e['cost_line']), BigDecimal::zero())->toScale(2);
        $day = $this->day($product, $date);

        return [
            'currency' => $currency, 'rate_id' => $adultRate->id, 'channel' => $adultRate->channel, 'season' => self::seasonRow($a['season']), 'season_id' => $a['season']?->id,
            'season_factor' => $a['factor'], 'adults' => $adults, 'children' => $children, 'infants' => $infants, 'units' => $units,
            'unit_adult' => (string) $unitAdult, 'unit_child' => (string) $unitChild, 'delta_pp' => (string) $delta->toScale(2), 'options' => $options->all(),
            'subtotal' => (string) $subtotal, 'discount' => (string) $discount, 'promotion_id' => $best?->id,
            'discount_label' => $best ? "{$best->name} (".EcomPricing::percent($best).')' : null, 'promo_code' => $best && $best->promo_code ? $code : null,
            'code_applies' => $codeApplies, 'extras' => array_map(fn ($e) => array_diff_key($e, ['cost_line' => 1]), $extras), 'extras_total' => (string) $extrasTotal,
            'total' => (string) $subtotal->minus($discount)->plus($extrasTotal)->toScale(2), 'cost' => (string) $cost->plus($extrasCost)->toScale(2),
            'list_price' => $day['list_price'] ?? null,
        ];
    }

    /** Convierte un monto entre PEN/USD con el TC del día (SIT-BR-12). */
    public function convert(string|BigDecimal $amount, string $from, string $to, ?string $fx = null): string
    {
        return (string) QuotePricing::convert(BigDecimal::of((string) $amount), $from, $to, $fx ?? $this->fx->rate('USD'))->toScale(2, RoundingMode::HalfUp);
    }

    public function usdRate(): string
    {
        return $this->fx->rate('USD');
    }

    /** SIT-BR-03: «desde» = mínimo precio adulto entre salidas con cupo o los próximos 150 días. */
    public function fromPrice(Product $product, ?Collection $departures = null): ?array
    {
        $today = $this->clock->today();
        $dates = $departures && $departures->isNotEmpty()
            ? $departures->map(fn ($d) => $d->departure_date->toDateString())->unique()->values()
            : collect(range($product->booking_advance_min_days, min(150, $product->booking_advance_max_days), 15))->map(fn ($n) => $today->copy()->addDays($n)->toDateString());
        $best = null;
        foreach ($dates as $date) {
            $day = $this->day($product, $date);
            if ($day && (! $best || (float) $day['adult'] < (float) $best['adult'])) {
                $best = $day + ['date' => $date];
            }
        }

        return $best;
    }

    public static function isTransfer(Product $product): bool
    {
        return $product->type?->name === 'TRASLADO';
    }

    public static function isPackage(Product $product): bool
    {
        return in_array($product->type?->name, ['PAQUETE', 'CIRCUITO', 'PROGRAMA TURÍSTICO'], true);
    }

    /** SIT-BR-10: el traslado aeropuerto → hotel solo para productos de Cusco que no sean paquetes ni el propio traslado. */
    public function transferAllowed(Product $product): bool
    {
        if (self::isTransfer($product) || self::isPackage($product) || ! $this->transferProduct()) {
            return false;
        }
        $path = (string) $product->primaryDestination?->path;
        $ids = array_filter(explode('/', $path));

        return Destination::whereIn('id', $ids)->whereRaw('lower(name) = ?', ['cusco'])->exists();
    }

    public function transferProduct(): ?Product
    {
        return $this->memo('transfer_product', fn () => Product::where('status', Product::ACTIVE)->whereHas('type', fn ($q) => $q->where('name', 'TRASLADO'))
            ->where('name', 'ilike', '%aeropuerto%')->orderBy('ref')->first());
    }

    /** @return list<array{code: string, label: string, amount: string, currency: string, amount_line: string, cost_line: string}> */
    private function extras(Product $product, array $chosen, int $people, string $date, string $lineCurrency): array
    {
        $out = [];
        if (filter_var($chosen['insurance'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $days = $product->duration_unit === 'días' ? max(1, (int) ceil((float) $product->duration_value)) : 1;
            $amount = BigDecimal::of(self::INSURANCE_USD_PER_PAX_DAY)->multipliedBy($people * $days)->toScale(2);
            $out[] = ['code' => 'INS', 'label' => "Seguro de asistencia ({$people} pax × {$days} día(s))", 'amount' => (string) $amount, 'currency' => 'USD',
                'amount_line' => $this->convert($amount, 'USD', $lineCurrency), 'cost_line' => $this->convert($amount->multipliedBy('0.6'), 'USD', $lineCurrency)];
        }
        if (filter_var($chosen['airport_transfer'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            if (! $this->transferAllowed($product)) {
                throw ApiException::unprocessable('SIT-BR-10', 'El traslado aeropuerto → hotel solo se ofrece con tours de Cusco (no en paquetes ni en el propio traslado).');
            }
            $transfer = $this->transferProduct();
            [$rate] = $this->ecom->rates($transfer, $date);
            if ($rate) {
                $r = $this->rateOn($rate, $date);
                $vehicles = (int) max(1, ceil($people / max(1, $transfer->maximum_passengers)));
                $amount = BigDecimal::of($r['amount'])->multipliedBy($vehicles)->toScale(2);
                $out[] = ['code' => 'TRF', 'label' => "{$transfer->name} ({$vehicles} vehículo(s))", 'amount' => (string) $amount, 'currency' => $rate->currency,
                    'amount_line' => $this->convert($amount, $rate->currency, $lineCurrency), 'cost_line' => $this->convert(BigDecimal::of($r['cost'])->multipliedBy($vehicles), $rate->currency, $lineCurrency)];
            }
        }

        return $out;
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

    /** Una opción por grupo; por defecto la de delta 0 (ECO-BR-04). @return Collection<int, array> */
    private function options(Product $product, array $chosen): Collection
    {
        return $product->configGroups()->with('options')->orderBy('sort_order')->get()->map(function ($g) use ($chosen) {
            $wanted = $chosen[$g->id] ?? $chosen[$g->name] ?? null;
            $option = $g->options->first(fn ($o) => $o->id === $wanted || $o->label === $wanted)
                ?? $g->options->first(fn ($o) => (float) $o->price_delta === 0.0) ?? $g->options->first();

            return $option ? ['group_id' => $g->id, 'group' => $g->name, 'option_id' => $option->id, 'option' => $option->label, 'delta_pp' => (string) $option->price_delta] : null;
        })->filter()->values();
    }
}
