<?php

namespace App\Events\Catalog;

use Illuminate\Foundation\Events\Dispatchable;

/** Spec 16 §8.2: se cerraron fechas con ventas (CAT-AV-04); Operaciones recibe las reservas afectadas. */
class AvailabilityClosedWithSales
{
    use Dispatchable;

    /** @param list<array{date: string, pax: int, reservations: list<string>}> $affected */
    public function __construct(public readonly string $productId, public readonly string $productRef, public readonly string $productName, public readonly array $affected) {}
}
