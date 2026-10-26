<?php

namespace App\Services\Quotes;

use App\Models\Catalog\Product;
use App\Models\Catalog\Rate;
use App\Models\Catalog\Season;
use App\Models\Crm\Client;
use App\Services\Catalog\SeasonService;
use App\Services\PricingService;
use App\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * Pricing de la cotización (spec 03 §5.1, PRC-01…07). Autoridad backend (RT-5): el frontend solo previsualiza.
 * Importes como string con 2 decimales, calculados con BigDecimal.
 */
class QuotePricing
{
    private const SCALE = 10;

    /** PRC-02: canal de tarifa preferido según el segmento CRM del cliente. */
    public const SEGMENT_CHANNEL = ['corporativo' => 'Corporativo', 'b2b' => 'B2B', 'grupos' => 'Corporativo'];

    public const VALIDITY_DAYS = 10;

    public function __construct(
        private readonly PricingService $pricing,
        private readonly SeasonService $seasons,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * PRC-01 puro: importe de tarifa × (factor de temporada de la fecha ÷ factor de la temporada base), convertido y round2.
     */
    public static function suggestAmount(string|float|int $amount, string|float|int $baseFactor, string|float|int $seasonFactor, string $from, string $to, string|float|int $fx): string
    {
        $value = BigDecimal::of((string) $amount)->multipliedBy((string) $seasonFactor)->dividedBy((string) $baseFactor, self::SCALE, RoundingMode::HalfUp);

        return (string) self::convert($value, $from, $to, $fx)->toScale(2, RoundingMode::HalfUp);
    }

    /** PRC-01.5 `conv(a, from, to)`: USD→PEN × fx; PEN→USD ÷ fx. */
    public static function convert(BigDecimal|string $amount, string $from, string $to, string|float|int $fx): BigDecimal
    {
        $amount = $amount instanceof BigDecimal ? $amount : BigDecimal::of($amount);

        return match (true) {
            $from === $to => $amount,
            $from === 'USD' && $to === 'PEN' => $amount->multipliedBy((string) $fx),
            default => $amount->dividedBy((string) $fx, self::SCALE, RoundingMode::HalfUp),
        };
    }

    /** PRC-06: convierte un importe unitario y redondea a 2. */
    public static function convertMoney(string|float|int $amount, string $from, string $to, string|float|int $fx): string
    {
        return (string) self::convert((string) $amount, $from, $to, $fx)->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * PRC-01: precio sugerido de una línea para la fecha y la moneda de la cotización.
     *
     * @return array{unit_cost: string, unit_price: string, season_id: ?string, season_factor: string, season: ?array, rate_snapshot: array}
     */
    public function suggest(Rate $rate, string $date, string $currency, string|float $fx): array
    {
        $base = $rate->season_id ? Season::withTrashed()->find($rate->season_id) : null;
        $baseFactor = $base ? (string) $base->factor : '1';
        $season = $this->seasons->resolve($date);
        $seasonFactor = $season ? (string) $season->factor : '1';
        $f = BigDecimal::of($seasonFactor)->dividedBy($baseFactor, 3, RoundingMode::HalfUp);

        return [
            'unit_cost' => self::suggestAmount((string) $rate->cost, $baseFactor, $seasonFactor, $rate->currency, $currency, $fx),
            'unit_price' => self::suggestAmount((string) $rate->price, $baseFactor, $seasonFactor, $rate->currency, $currency, $fx),
            'season_id' => $season?->id,
            'season_factor' => (string) $f,
            'season' => $season ? ['id' => $season->id, 'ref' => $season->ref, 'name' => $season->name, 'factor' => (string) $season->factor] : null,
            'rate_snapshot' => $this->rateSnapshot($rate, $base),
        ];
    }

    public function rateSnapshot(Rate $rate, ?Season $base = null): array
    {
        return [
            'id' => $rate->id, 'ref' => $rate->ref, 'type' => $rate->rate_type, 'segment' => $rate->segment, 'channel' => $rate->channel,
            'currency' => $rate->currency, 'cost' => (string) $rate->cost, 'price' => (string) $rate->price, 'price_basis' => $rate->price_basis,
            'valid_from' => $rate->valid_from->toDateString(), 'valid_to' => $rate->valid_to->toDateString(),
            'base_season' => $base ? ['ref' => $base->ref, 'name' => $base->name, 'factor' => (string) $base->factor] : null,
        ];
    }

    /** PRD-BR-005: tarifa activa con vigencia que contiene la fecha. */
    public static function rateValidOn(Rate $rate, string $date): bool
    {
        return $rate->status !== 'inactiva' && $rate->valid_from->toDateString() <= $date && $date <= $rate->valid_to->toDateString();
    }

    /**
     * PRC-02: canal del segmento del cliente (no Niño) → Directo (no Niño) → primera válida → ninguna (precio manual).
     *
     * @param  Collection<int, Rate>  $rates
     */
    public function defaultRate(Collection $rates, string $date, ?Client $client): ?Rate
    {
        $valid = $rates->filter(fn (Rate $r) => self::rateValidOn($r, $date))->sortBy('ref')->values();
        $channel = self::SEGMENT_CHANNEL[$client?->segment_code ?? ''] ?? 'Directo';

        return $valid->first(fn (Rate $r) => $r->channel === $channel && $r->segment !== 'Niño')
            ?? $valid->first(fn (Rate $r) => $r->channel === 'Directo' && $r->segment !== 'Niño')
            ?? $valid->first();
    }

    /**
     * PRC-03: cantidad, pax y segmento por defecto de una línea nueva.
     *
     * @return array{qty: int, pax: int, segment: string}
     */
    public static function lineDefaults(Product $product, int $adt, int $chd, ?Rate $rate = null): array
    {
        if ($product->type?->name === 'TRASLADO') {
            return ['qty' => 2, 'pax' => 1, 'segment' => 'Pasajero'];
        }

        return ['qty' => 1, 'pax' => max(1, $adt + $chd), 'segment' => $rate?->segment === 'Niño' ? 'Niño' : 'Adulto'];
    }

    /**
     * PRC-04: totales de la versión + markup derivado.
     *
     * @param  list<array{unit_cost: mixed, unit_price: mixed, qty: mixed, pax: mixed}>  $lines
     * @return array{cost: string, sale: string, discount: string, net: string, tax: string, total: string, profit: string, marginPct: string, markupPct: string}
     */
    public function totals(array $lines, string|float|int $discountPct, string|float|int $taxPct): array
    {
        $totals = $this->pricing->quoteTotals(array_map(fn (array $l) => [
            'unitCost' => $l['unit_cost'], 'unitPrice' => $l['unit_price'], 'qty' => $l['qty'], 'pax' => $l['pax'],
        ], $lines), $discountPct, $taxPct);

        $cost = BigDecimal::of($totals['cost']);
        $totals['markupPct'] = $cost->isZero()
            ? '0.00'
            : (string) BigDecimal::of($totals['sale'])->dividedBy($cost, self::SCALE, RoundingMode::HalfUp)->minus(1)->multipliedBy(100)->toScale(2, RoundingMode::HalfUp);

        return $totals;
    }

    /** Margen de línea mostrado en el constructor: (precio − costo) / precio × 100. */
    public static function lineMargin(string|float $unitCost, string|float $unitPrice): string
    {
        $price = BigDecimal::of((string) $unitPrice);

        return $price->isZero() ? '0.00' : (string) $price->minus((string) $unitCost)->multipliedBy(100)->dividedBy($price, 2, RoundingMode::HalfUp);
    }

    /** PRC-07: 0 % para cliente no domiciliado; si no, el IGV del tenant (18). */
    public function defaultTax(?Client $client): string
    {
        if ($client !== null && $client->country !== null && $client->country !== 'Perú') {
            return '0';
        }

        return (string) ($this->tenant->tenant()?->igv_pct ?? 18);
    }

    /** Tipo de cambio vigente (`settings.fx`), congelado por versión. */
    public function fx(): string
    {
        return (string) ($this->tenant->tenant()?->fxToPen('USD') ?? 3.75);
    }

    /** Días de validez por defecto (QTE-BR-27). */
    public function validityDays(): int
    {
        return (int) data_get($this->tenant->tenant()?->settings, 'sales.quote_validity_days', self::VALIDITY_DAYS);
    }

    public function toPen(string|float $amount, string $currency, string|float $fx): string
    {
        return $this->pricing->toPen($amount, $currency, $fx);
    }
}
