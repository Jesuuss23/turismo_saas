<?php

namespace App\Services\Platform\Gateway;

final class ChargeResult
{
    public function __construct(
        public readonly bool $succeeded,
        public readonly ?string $reference,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
        public readonly bool $error = false,
    ) {}
}
