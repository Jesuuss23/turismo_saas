<?php

namespace App\Services\Platform;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Precios, periodos y montos de la suscripción (sin IGV salvo que se indique). */
final class BillingMath
{
    /** Precio del ciclo: el pactado (TNT-0004, §11) o el congelado por grandfathering (PLN-BR-02) si sigue vigente; si no, el del catálogo. */
    public static function price(object $sub, ?string $planId = null, ?string $cycle = null, ?Carbon $on = null): string
    {
        $planId ??= $sub->plan_id;
        $cycle ??= $sub->cycle;
        $on ??= PlatformClock::today();
        if ($sub->negotiated_price !== null && $planId === $sub->plan_id && $cycle === $sub->cycle
            && ($sub->price_locked_until === null || $sub->price_locked_until >= $on->toDateString())) {
            return (string) BigDecimal::of((string) $sub->negotiated_price)->toScale(2);
        }

        return self::catalogPrice($planId, $cycle);
    }

    public static function catalogPrice(string $planId, string $cycle): string
    {
        $plan = DB::connection(PlatformCatalog::CONNECTION)->table('plans')->where('id', $planId)->first(['price_monthly', 'price_year']);

        return (string) BigDecimal::of((string) ($cycle === 'annual' ? $plan->price_year : $plan->price_monthly))->toScale(2);
    }

    /** Precio mensual del add-on según el plan (extra_users depende del plan). */
    public static function addonPrice(string $code, string $planId): string
    {
        $db = DB::connection(PlatformCatalog::CONNECTION);
        $price = $db->table('addon_prices')->where('addon_code', $code)->where('plan_id', $planId)->value('price')
            ?? $db->table('addons')->where('code', $code)->value('price_month');

        return (string) BigDecimal::of((string) $price)->toScale(2);
    }

    /** Fin inclusivo del periodo que empieza en `$start`. */
    public static function periodEnd(Carbon $start, string $cycle): Carbon
    {
        return ($cycle === 'annual' ? $start->copy()->addYearNoOverflow() : $start->copy()->addMonthNoOverflow())->subDay();
    }

    /** Valor mensualizado (MRR) de un precio de ciclo. */
    public static function monthly(string $price, string $cycle): string
    {
        return $cycle === 'annual' ? (string) BigDecimal::of($price)->dividedBy(12, 2, RoundingMode::HalfUp) : (string) BigDecimal::of($price)->toScale(2);
    }

    /** Monto prorrateado de un cargo mensual por los días que quedan del periodo actual (el de hoy cuenta). */
    public static function prorate(string $monthly, object $sub, ?Carbon $today = null): array
    {
        $today ??= PlatformClock::today();
        $start = Carbon::parse($sub->current_period_start, PlatformClock::TZ);
        $end = Carbon::parse($sub->current_period_end, PlatformClock::TZ);
        $periodDays = (int) $start->diffInDays($end) + 1;
        $daysLeft = max(0, min($periodDays, (int) $today->diffInDays($end, false) + 1));
        $cyclePrice = BigDecimal::of($monthly)->multipliedBy($sub->cycle === 'annual' ? 12 : 1);

        return ['days_left' => $daysLeft, 'period_days' => $periodDays,
            'amount' => (string) $cyclePrice->multipliedBy($daysLeft)->dividedBy($periodDays, 2, RoundingMode::HalfUp)];
    }

    public static function igv(string $subtotal): string
    {
        $pct = PlatformInvoiceService::issuer()['igv_pct'];

        return (string) BigDecimal::of($subtotal)->multipliedBy($pct)->dividedBy(100, 2, RoundingMode::HalfUp);
    }

    public static function money(string|float $amount): string
    {
        return 'S/ '.number_format((float) $amount, 2, '.', ',');
    }
}
