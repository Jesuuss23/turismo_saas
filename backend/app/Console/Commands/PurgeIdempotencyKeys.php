<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Job diario: purga las Idempotency-Key expiradas (§3.8). */
class PurgeIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:purge';

    protected $description = 'Elimina las Idempotency-Key expiradas';

    public function handle(): int
    {
        $deleted = DB::connection('pgsql_admin')->table('sys_idempotency_keys')->where('expires_at', '<', now())->delete();
        $this->info("{$deleted} claves eliminadas.");

        return self::SUCCESS;
    }
}
