<?php

namespace App\Domain\Bookings;

use App\Models\Catalog\Product;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * Caché Redis de disponibilidad (spec 16 §8.3): `avail:{tenant}:{product}:{yyyy-mm}:v{version}` con el mes calculado con
 * `seats=0` (TTL 10 min); `seats` se aplica en memoria sobre `left`. La versión es `avail:ver:{tenant}:{product}` (INCR)
 * combinada con `rules:ver:{tenant}`, así invalidar es O(1) y sin `KEYS`. El día de hoy entra en la clave (medianoche ⇒ clave nueva).
 */
class AvailabilityCache
{
    public const TTL_MINUTES = 10;

    public function __construct(private readonly TenantContext $tenant, private readonly RuleResolver $rules) {}

    public function version(Product|string $product): string
    {
        return ((int) Cache::get($this->versionKey($product), 0)).'.'.$this->rules->version();
    }

    /** AvailabilityRangeChanged, reserva/hold creado o liberado, salida modificada. */
    public function bump(Product|string $product): void
    {
        Cache::increment($this->versionKey($product));
    }

    /** @param callable(): array $compute días del mes con seats=0 @return list<array> */
    public function month(Product $product, string $ym, string $today, callable $compute): array
    {
        $key = "avail:{$this->tenant->id()}:{$product->id}:{$ym}:v{$this->version($product)}:{$today}";

        return Cache::remember($key, now()->addMinutes(self::TTL_MINUTES), $compute);
    }

    private function versionKey(Product|string $product): string
    {
        $id = $product instanceof Product ? $product->id : $product;

        return "avail:ver:{$this->tenant->id()}:{$id}";
    }
}
