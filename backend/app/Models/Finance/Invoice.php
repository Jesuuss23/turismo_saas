<?php

namespace App\Models\Finance;

use App\Models\TenantModel;

/** Comprobante (módulo Finanzas). Reservas emite notas de crédito por reembolso. */
class Invoice extends TenantModel
{
    protected $table = 'fin_invoices';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'issued_on' => 'date'];
    }
}
