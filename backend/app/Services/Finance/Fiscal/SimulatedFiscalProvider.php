<?php

namespace App\Services\Finance\Fiscal;

use App\Models\Finance\Invoice;

/**
 * OSE simulado (prototipo: aceptación inmediata). No representa ninguna API real. Para ejercitar el circuito de observaciones,
 * la exportación sin IGV queda `observado` en el primer envío (sustento de exportación, §12 P-01) y se acepta al reenviar.
 */
class SimulatedFiscalProvider implements FiscalProvider
{
    public function name(): string
    {
        return 'simulado';
    }

    public function send(Invoice $invoice, int $attempt): FiscalResult
    {
        $ticket = $invoice->ose_ticket ?? 'SIM-'.strtoupper(substr(hash('sha256', $invoice->id), 0, 10));
        if ($invoice->operation_type === 'exportacion' && $attempt === 1 && ! $invoice->isCreditNote()) {
            return FiscalResult::ok('observado', $ticket, 'SIM-OBS', 'Respuesta OSE simulada: verificar el sustento de exportación de servicios (documento y condición de no domiciliado).');
        }

        return FiscalResult::ok('aceptado', $ticket, 'SIM-0', "Respuesta OSE simulada: el comprobante {$invoice->number} ha sido aceptado.");
    }

    public function status(Invoice $invoice): FiscalResult
    {
        return match ($invoice->status) {
            'pendiente_envio' => FiscalResult::temporary('El comprobante aún no fue enviado al OSE.'),
            'emitido' => FiscalResult::ok('aceptado', $invoice->ose_ticket, 'SIM-0', "Respuesta OSE simulada: el comprobante {$invoice->number} ha sido aceptado."),
            default => FiscalResult::ok($invoice->status, $invoice->ose_ticket, $invoice->ose_response_code, $invoice->ose_response_message),
        };
    }
}
