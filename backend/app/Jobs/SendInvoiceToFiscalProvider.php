<?php

namespace App\Jobs;

use App\Services\Finance\Fiscal\FiscalGateway;
use App\Tenancy\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Outbox fiscal (FIN-BR-49, PROC-065): envía o reenvía el comprobante al OSE/PSE. Los reintentos con backoff los programa el gateway. */
class SendInvoiceToFiscalProvider implements ShouldQueue
{
    use Queueable, TenantAware;

    public function __construct(public string $invoiceId, public string $action = 'send', public int $attempt = 1)
    {
        $this->onQueue('integrations');
        $this->captureTenant();
    }

    public function handle(FiscalGateway $gateway): void
    {
        $gateway->process($this->invoiceId, $this->action, $this->attempt);
    }
}
