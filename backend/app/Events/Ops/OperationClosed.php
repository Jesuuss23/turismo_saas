<?php

namespace App\Events\Ops;

use Illuminate\Foundation\Events\Dispatchable;

/** PROC-039: habilita liquidación de CxP y rentabilidad (Finanzas) y postventa (CRM). */
class OperationClosed
{
    use Dispatchable;

    public function __construct(public readonly string $orderId) {}
}
