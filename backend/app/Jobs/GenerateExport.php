<?php

namespace App\Jobs;

use App\Models\Admin\ReportExport;
use App\Services\Reports\ExportService;
use App\Tenancy\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** PROC-061: Job → Procesar → Archivo → Disponible (cola `reports`). */
class GenerateExport implements ShouldQueue
{
    use Queueable, TenantAware;

    public int $tries = 3;

    public function __construct(public string $exportId)
    {
        $this->onQueue('reports');
        $this->captureTenant();
    }

    public function handle(ExportService $exports): void
    {
        $exports->generate(ReportExport::findOrFail($this->exportId));
    }
}
