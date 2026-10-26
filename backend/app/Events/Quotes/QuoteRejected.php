<?php

namespace App\Events\Quotes;

use Illuminate\Foundation\Events\Dispatchable;

/** El cliente rechazó la cotización (spec 03 §8). */
class QuoteRejected
{
    use Dispatchable;

    public function __construct(
        public readonly string $quoteId,
        public readonly ?string $opportunityId,
    ) {}
}
