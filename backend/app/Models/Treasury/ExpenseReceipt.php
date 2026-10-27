<?php

namespace App\Models\Treasury;

use App\Models\TenantModel;

/** Comprobante del gasto de caja chica (spec 12 §3.3, TES-BR-12). */
class ExpenseReceipt extends TenantModel
{
    public const TYPES = [
        'invoice' => 'Factura', 'sales_receipt' => 'Boleta', 'ticket' => 'Ticket', 'fee_receipt' => 'Recibo por honorarios',
        'mobility_sheet' => 'Planilla de movilidad', 'sworn_statement' => 'Declaración jurada',
    ];

    protected $table = 'fin_expense_receipts';
}
