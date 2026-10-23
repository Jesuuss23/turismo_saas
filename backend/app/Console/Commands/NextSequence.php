<?php

namespace App\Console\Commands;

use App\Services\SequenceService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Asigna el siguiente correlativo de un tenant (soporte y prueba de concurrencia X-15). */
class NextSequence extends Command
{
    protected $signature = 'sequences:next {tenant : id del tenant} {doc_type} {--series=default}';

    protected $description = 'Asigna y muestra el siguiente número de un correlativo';

    public function handle(TenantContext $context, SequenceService $sequences): int
    {
        $number = $context->run($this->argument('tenant'), fn () => $sequences->next($this->argument('doc_type'), $this->option('series')));
        $this->line($number);

        return self::SUCCESS;
    }
}
