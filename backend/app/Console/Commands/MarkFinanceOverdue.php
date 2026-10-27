<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** `fin:mark-overdue` (spec 07 §8.2): CxC y CxP vencidas (T21). */
class MarkFinanceOverdue extends Command
{
    protected $signature = 'fin:mark-overdue';

    protected $description = 'Finanzas: CxC y CxP vencidas (T21)';

    public function handle(): int
    {
        return $this->call('fin:run', ['job' => 'mark-overdue']);
    }
}
