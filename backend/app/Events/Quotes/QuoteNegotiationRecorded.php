<?php

namespace App\Events\Quotes;

use Illuminate\Foundation\Events\Dispatchable;

/** Se registró una negociación en la cotización (spec 03). Lo consume el CRM (CRM-BR-27). */
class QuoteNegotiationRecorded
{
    use Dispatchable;

    public function __construct(
        public readonly string $quoteId,
        public readonly ?string $opportunityId,
    ) {}
}
