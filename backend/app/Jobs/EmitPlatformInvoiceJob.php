<?php

namespace App\Jobs;

use App\Services\Platform\PlatformInvoiceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Spec 13 §8.2: XML UBL 2.1 → OSE → CDR → PDF de la factura de plataforma, con reintentos y backoff (PROC-065). */
class EmitPlatformInvoiceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 1800, 7200];

    public function __construct(public string $invoiceId)
    {
        $this->onQueue('documents');
    }

    public function handle(PlatformInvoiceService $invoices): void
    {
        $invoices->emit($this->invoiceId);
    }
}
