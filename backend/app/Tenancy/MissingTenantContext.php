<?php

namespace App\Tenancy;

use RuntimeException;

class MissingTenantContext extends RuntimeException
{
    public function __construct(string $message = 'Operación sin contexto de tenant (SEC-BR-01)')
    {
        parent::__construct($message);
    }
}
