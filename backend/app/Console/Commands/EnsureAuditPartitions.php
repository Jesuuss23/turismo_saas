<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Crea por adelantado las particiones mensuales de audit_events (programar mensualmente). */
class EnsureAuditPartitions extends Command
{
    protected $signature = 'audit:partitions {--months=12}';

    protected $description = 'Crea las particiones mensuales de audit_events de los próximos meses';

    public function handle(): int
    {
        DB::connection('pgsql_admin')->statement(
            "SELECT audit_events_ensure_partition((date_trunc('month', now()) + make_interval(months => m))::date) FROM generate_series(0, ?) AS m",
            [(int) $this->option('months')],
        );
        $this->info('Particiones de auditoría al día.');

        return self::SUCCESS;
    }
}
