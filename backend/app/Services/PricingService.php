<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Motor de precios (§5.1, idéntico a `Pricing.calc`). Siempre BigDecimal, nunca float.
 * Los importes se devuelven como string con 2 decimales; marginPct con 2 decimales.
 */
class PricingService
{
    private const SCALE = 10;

    /**
     * @param  array{cost?: mixed, extra?: mixed, markupPct?: mixed, markupFixed?: mixed, discountPct?: mixed, discountFixed?: mixed, taxPct?: mixed, rounding?: mixed, commissionPct?: mixed}  $in
     * @return array{base: string, markup: string, subtotal: string, discount: string, net: string, tax: string, total: string, commission: string, profit: string, marginPct: string}
     */
    public function calc(array $in): array
    {
        $d = fn (string $k, string $default = '0') => BigDecimal::of((string) ($in[$k] ?? $default));
        $hundred = BigDecimal::of(100);

        $base = $d('cost')->plus($d('extra'));
        $markup = $base->multipliedBy($d('markupPct'))->dividedBy($hundred, self::SCALE, RoundingMode::HalfUp)->plus($d('markupFixed'));
        $subtotal = $base->plus($markup);
        $discount = $subtotal->multipliedBy($d('discountPct'))->dividedBy($hundred, self::SCALE, RoundingMode::HalfUp)->plus($d('discountFixed'));
        $net = $subtotal->minus($discount);
        $tax = $net->multipliedBy($d('taxPct'))->dividedBy($hundred, self::SCALE, RoundingMode::HalfUp);
        $total = $this->roundTo($net->plus($tax), $d('rounding', '0.10'));
        $commission = $net->multipliedBy($d('commissionPct'))->dividedBy($hundred, self::SCALE, RoundingMode::HalfUp);
        $profit = $net->minus($base)->minus($commission);

        return [
            'base' => self::money($base),
            'markup' => self::money($markup),
            'subtotal' => self::money($subtotal),
            'discount' => self::money($discount),
            'net' => self::money($net),
            'tax' => self::money($tax),
            'total' => self::money($total),
            'commission' => self::money($commission),
            'profit' => self::money($profit),
            'marginPct' => self::pct($profit, $net),
        ];
    }

    /**
     * Totales de una versión de cotización (§5.1 quoteTotals).
     *
     * @param  list<array{unitCost: mixed, unitPrice: mixed, qty?: mixed, pax?: mixed}>  $lines
     * @return array{cost: string, sale: string, discount: string, net: string, tax: string, total: string, profit: string, marginPct: string}
     */
    public function quoteTotals(array $lines, string|int|float $discountPct = 0, string|int|float $taxPct = 0): array
    {
        $cost = BigDecimal::zero();
        $sale = BigDecimal::zero();
        foreach ($lines as $line) {
            $units = BigDecimal::of((string) ($line['qty'] ?? 1))->multipliedBy((string) ($line['pax'] ?? 1));
            $cost = $cost->plus(BigDecimal::of((string) $line['unitCost'])->multipliedBy($units));
            $sale = $sale->plus(BigDecimal::of((string) $line['unitPrice'])->multipliedBy($units));
        }

        $hundred = BigDecimal::of(100);
        $discount = $sale->multipliedBy((string) $discountPct)->dividedBy($hundred, self::SCALE, RoundingMode::HalfUp);
        $net = $sale->minus($discount);
        $tax = $net->multipliedBy((string) $taxPct)->dividedBy($hundred, self::SCALE, RoundingMode::HalfUp);
        $profit = $net->minus($cost);

        return [
            'cost' => self::money($cost),
            'sale' => self::money($sale),
            'discount' => self::money($discount),
            'net' => self::money($net),
            'tax' => self::money($tax),
            'total' => self::money($net->plus($tax)),
            'profit' => self::money($profit),
            'marginPct' => self::pct($profit, $net),
        ];
    }

    /**
     * Temporada aplicable: activas que contienen la fecha, menor `priority` primero (0 = feriados).
     *
     * @template T of array{status: string, start: string, end: string, priority: int}
     *
     * @param  list<T>  $seasons
     * @return T|null
     */
    public function seasonFor(CarbonInterface|string $date, array $seasons): ?array
    {
        $day = Carbon::parse($date)->toDateString();

        $matching = array_filter($seasons, fn (array $s) => $s['status'] === 'activa' && $s['start'] <= $day && $day <= $s['end']);
        usort($matching, fn (array $a, array $b) => $a['priority'] <=> $b['priority']);

        return $matching[0] ?? null;
    }

    /** Conversión a PEN con el tipo de cambio congelado del documento (SEC-BR-19). */
    public function toPen(string|int|float $amount, string $currency, string|int|float $fxRate): string
    {
        $value = BigDecimal::of((string) $amount);

        return self::money($currency === 'USD' ? $value->multipliedBy((string) $fxRate) : $value);
    }

    /** Redondeo al múltiplo más cercano de $step (0.10 por defecto), mitad hacia arriba. */
    private function roundTo(BigDecimal $value, BigDecimal $step): BigDecimal
    {
        if ($step->isZero()) {
            return $value;
        }

        return $value->dividedBy($step, 0, RoundingMode::HalfUp)->multipliedBy($step);
    }

    private static function money(BigDecimal $value): string
    {
        return (string) $value->toScale(2, RoundingMode::HalfUp);
    }

    private static function pct(BigDecimal $part, BigDecimal $whole): string
    {
        return $whole->isZero()
            ? '0.00'
            : (string) $part->multipliedBy(100)->dividedBy($whole, 2, RoundingMode::HalfUp);
    }
}
