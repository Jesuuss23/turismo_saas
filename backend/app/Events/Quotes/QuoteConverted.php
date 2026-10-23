<?php

namespace App\Events\Quotes;

use Illuminate\Foundation\Events\Dispatchable;

/** La cotización se convirtió en reserva (spec 03/04). Lo consume el CRM (CRM-BR-27). */
class QuoteConverted
{
    use Dispatchable;

    public function __construct(
        public readonly string $quoteId,
        public readonly ?string $opportunityId,
    ) {}
}
