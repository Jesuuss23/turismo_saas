<?php

namespace App\Events\Quotes;

use Illuminate\Foundation\Events\Dispatchable;

/** Se reasignó el asesor de la cotización (spec 03 §8). */
class QuoteReassigned
{
    use Dispatchable;

    public function __construct(
        public readonly string $quoteId,
        public readonly ?string $opportunityId,
    ) {}
}
