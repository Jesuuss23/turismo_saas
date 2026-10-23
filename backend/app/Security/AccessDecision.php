<?php

namespace App\Security;

final class AccessDecision
{
    /**
     * @param  list<string>  $required
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly string $reason = '',
        public readonly array $required = [],
        public readonly ?string $redirect = null,
    ) {}

    public static function allow(): self
    {
        return new self(true);
    }

    public static function login(): self
    {
        return new self(false, 'Inicie sesión para continuar', redirect: 'login');
    }
}
