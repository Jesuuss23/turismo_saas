<?php

namespace App\Services\Bookings;

use App\Models\Catalog\Product;
use App\Models\Catalog\ProductItem;
use App\Services\Quotes\QuotePricing;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * `servicesFromItem` (§4.3 paso 10): cada ítem se descompone en servicios por componente del producto,
 * prorrateando costo y precio por el peso de costo del componente. El residuo de redondeo va a la última
 * línea para que Σ = costT / saleT exacto.
 */
final class ServiceBreakdown
{
    /**
     * @return list<array{name: string, catalog_service_id: ?string, provider_id: ?string, cost: string, price: string, is_required: bool, time: ?string}>
     */
    public static function split(Product $product, int $pax, int $qty, string $unitCost, string $unitPrice, string $discountPct, string $currency, string $fx): array
    {
        $costT = BigDecimal::of($unitCost)->multipliedBy($qty)->multipliedBy($pax)->toScale(2, RoundingMode::HalfUp);
        $saleT = BigDecimal::of($unitPrice)->multipliedBy($qty)->multipliedBy($pax)
            ->multipliedBy(BigDecimal::of(100)->minus($discountPct))->dividedBy(100, 2, RoundingMode::HalfUp);

        return self::splitTotals($product, $pax, (string) $costT, (string) $saleT, $currency, $fx);
    }

    /** Reparte totales ya calculados (reserva directa: adultos y niños con tarifas distintas). */
    public static function splitTotals(Product $product, int $pax, string $costTotal, string $saleTotal, string $currency, string $fx): array
    {
        $costT = BigDecimal::of($costTotal)->toScale(2, RoundingMode::HalfUp);
        $saleT = BigDecimal::of($saleTotal)->toScale(2, RoundingMode::HalfUp);

        $product->loadMissing(['items.service', 'itinerary.days.items']);
        $components = $product->items->filter(fn (ProductItem $i) => $i->is_included !== false && $i->service !== null)->values();
        if ($components->isEmpty()) {
            return [[
                'name' => "{$product->name} ({$pax} pax)", 'catalog_service_id' => null, 'provider_id' => $product->main_provider_id,
                'cost' => (string) $costT, 'price' => (string) $saleT, 'is_required' => true, 'time' => self::firstTime($product),
            ]];
        }

        $weights = $components->map(function (ProductItem $i) use ($currency, $fx, $pax) {
            $cost = (string) ($i->unit_cost_override ?? $i->service->cost);
            $units = BigDecimal::of((string) $i->quantity)->isZero() ? BigDecimal::one() : BigDecimal::of((string) $i->quantity);

            return QuotePricing::convert($cost, $i->service->currency, $currency, $fx)->multipliedBy($units)->multipliedBy($i->service->unit === 'pax' ? $pax : 1);
        });
        $sum = $weights->reduce(fn (BigDecimal $carry, BigDecimal $w) => $carry->plus($w), BigDecimal::zero());
        $count = $components->count();
        $rows = [];
        $costLeft = $costT;
        $saleLeft = $saleT;
        foreach ($components as $n => $item) {
            $last = $n === $count - 1;
            $share = fn (BigDecimal $total) => $sum->isZero()
                ? $total->dividedBy($count, 2, RoundingMode::HalfUp)
                : $total->multipliedBy($weights[$n])->dividedBy($sum, 2, RoundingMode::HalfUp);
            $cost = $last ? $costLeft : $share($costT);
            $price = $last ? $saleLeft : $share($saleT);
            $costLeft = $costLeft->minus($cost);
            $saleLeft = $saleLeft->minus($price);
            $units = (float) $item->quantity;
            $rows[] = [
                'name' => $item->service->name.($units > 1 ? ' ×'.rtrim(rtrim(number_format($units, 2, '.', ''), '0'), '.') : ''),
                'catalog_service_id' => $item->service->id,
                'provider_id' => $item->service->provider_id,
                'cost' => (string) $cost,
                'price' => (string) $price,
                'is_required' => $item->is_required !== false,
                'time' => $n === 0 ? self::firstTime($product) : self::timeIn($item->notes),
            ];
        }

        return $rows;
    }

    /** Primera hora del día 1 del itinerario. */
    private static function firstTime(Product $product): ?string
    {
        $day = $product->itinerary?->days->sortBy('day_number')->first();
        $time = $day?->items->first(fn ($i) => $i->start_time !== null)?->start_time;

        return $time ? substr((string) $time, 0, 5) : null;
    }

    private static function timeIn(?string $notes): ?string
    {
        return $notes && preg_match('/\b([01]\d|2[0-3]):[0-5]\d\b/', $notes, $m) ? $m[0] : null;
    }
}
