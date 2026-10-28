<?php

namespace App\Events\Catalog;

use Illuminate\Foundation\Events\Dispatchable;

/** Spec 16 §8.2: rango creado/editado/eliminado, fechas cerradas u operación cambiada (invalida la caché del producto). */
class AvailabilityRangeChanged
{
    use Dispatchable;

    public function __construct(public readonly string $productId) {}
}
