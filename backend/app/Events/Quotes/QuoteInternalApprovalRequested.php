<?php

namespace App\Events\Quotes;

use Illuminate\Foundation\Events\Dispatchable;

/** Se solicitó aprobación interna a gerencia (spec 03 §8). */
class QuoteInternalApprovalRequested
{
    use Dispatchable;

    public function __construct(
        public readonly string $quoteId,
        public readonly ?string $opportunityId,
    ) {}
}
