<?php

namespace App\Events\Quotes;

use Illuminate\Foundation\Events\Dispatchable;

/** JOB-02 venció la cotización (spec 03 §8). */
class QuoteExpired
{
    use Dispatchable;

    public function __construct(
        public readonly string $quoteId,
        public readonly ?string $opportunityId,
    ) {}
}
