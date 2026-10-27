<?php

namespace App\Services\Platform;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/**
 * SUB-BR-01: prorrateo del upgrade. Mismo ciclo: `precio_nuevo × días_restantes / días_periodo − crédito del plan actual`.
 * Mensual → anual: inicia un periodo nuevo de 12 meses y cobra el precio anual menos el crédito. Un neto negativo va a `credit_balance`.
 * El periodo es inclusivo (del día de inicio al último día cubierto) y el día de hoy cuenta como restante.
 */
final class ProrationCalculator
{
    /**
     * @return array{days_left: int, period_days: int, charge: string, credit: string, net: string, igv: string, total: string,
     *     period_start: string, period_end: string, new_period: bool}
     */
    public static function compute(string $currentPrice, string $currentCycle, string $newMonthly, string $newYear, string $newCycle,
        Carbon $start, Carbon $end, Carbon $today, string $igvPct = '18.00'): array
    {
        $periodDays = (int) $start->diffInDays($end) + 1;
        $daysLeft = max(0, min($periodDays, (int) $today->diffInDays($end, false) + 1));
        $ratio = fn (string $price) => BigDecimal::of($price)->multipliedBy($daysLeft)->dividedBy($periodDays, 2, RoundingMode::HalfUp);
        $credit = $ratio($currentPrice);
        $newPeriod = $currentCycle === 'monthly' && $newCycle === 'annual';
        $charge = $newPeriod ? BigDecimal::of($newYear)->toScale(2) : $ratio($newCycle === 'annual' ? $newYear : $newMonthly);
        $net = $charge->minus($credit);
        $taxable = $net->isNegative() ? BigDecimal::zero()->toScale(2) : $net;
        $igv = $taxable->multipliedBy($igvPct)->dividedBy(100, 2, RoundingMode::HalfUp);

        return [
            'days_left' => $daysLeft, 'period_days' => $periodDays, 'charge' => (string) $charge, 'credit' => (string) $credit, 'net' => (string) $net,
            'igv' => (string) $igv, 'total' => (string) $taxable->plus($igv),
            'period_start' => ($newPeriod ? $today : $start)->toDateString(),
            'period_end' => ($newPeriod ? $today->copy()->addYearNoOverflow()->subDay() : $end)->toDateString(),
            'new_period' => $newPeriod,
        ];
    }
}
