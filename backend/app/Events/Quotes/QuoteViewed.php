<?php

namespace App\Events\Quotes;

use Illuminate\Foundation\Events\Dispatchable;

/** El cliente abrió el enlace o se registró la vista (spec 03 §8). */
class QuoteViewed
{
    use Dispatchable;

    public function __construct(
        public readonly string $quoteId,
        public readonly ?string $opportunityId,
    ) {}
}
