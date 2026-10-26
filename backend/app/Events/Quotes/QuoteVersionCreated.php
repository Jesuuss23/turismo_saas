<?php

namespace App\Events\Quotes;

use Illuminate\Foundation\Events\Dispatchable;

/** Se creó una versión nueva o una cotización duplicada (spec 03 §8). */
class QuoteVersionCreated
{
    use Dispatchable;

    public function __construct(
        public readonly string $quoteId,
        public readonly ?string $opportunityId,
    ) {}
}
