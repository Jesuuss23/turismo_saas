<?php

namespace App\Events\Catalog;

use Illuminate\Foundation\Events\Dispatchable;

/** Spec 16 §8.2: regla del tenant u override de producto modificado (invalida reglas y disponibilidad). */
class BookingRuleChanged
{
    use Dispatchable;

    public function __construct(public readonly string $ruleKey, public readonly ?string $productId = null) {}
}
