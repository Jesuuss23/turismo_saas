<?php

namespace App\Services\Finance\Fiscal;

use App\Models\Finance\Invoice;

/**
 * Adaptador OSE/PSE (Regla transversal 20, §12 P-02). La integración concreta está POR VALIDAR: el tenant opera en modo
 * `simulado` hasta homologar un proveedor. `send` envía el comprobante; `status` consulta la respuesta (CDR).
 */
interface FiscalProvider
{
    public function name(): string;

    public function send(Invoice $invoice, int $attempt): FiscalResult;

    public function status(Invoice $invoice): FiscalResult;
}
