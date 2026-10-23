<?php

namespace App\Events\Quotes;

use Illuminate\Foundation\Events\Dispatchable;

/** Se creó una cotización (spec 03). Lo consume el CRM (CRM-BR-27). */
class QuoteCreated
{
    use Dispatchable;

    public function __construct(
        public readonly string $quoteId,
        public readonly ?string $opportunityId,
    ) {}
}
