<?php

namespace App\Events\Quotes;

use Illuminate\Foundation\Events\Dispatchable;

/** Gerencia aprobó o rechazó la aprobación interna (spec 03 §8). */
class QuoteInternalApprovalDecided
{
    use Dispatchable;

    public function __construct(
        public readonly string $quoteId,
        public readonly ?string $opportunityId,
    ) {}
}
