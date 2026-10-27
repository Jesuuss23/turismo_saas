<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tenants sobre los que corre un job programado: en prueba o activos (los suspendidos tienen sus jobs pausados, §4.1)
 * y sin ese job pausado en su scheduler (JOB-BR-02). `$only` = “Ejecutar ahora” de un tenant (JOB-BR-01).
 */
final class OperatingTenants
{
    /** @return Collection<int, string> */
    public static function ids(?string $only = null, ?string $job = null): Collection
    {
        return DB::connection('pgsql_admin')->table('tenants as t')->whereIn('t.status', ['prueba', 'activo'])
            ->when($only !== null, fn ($q) => $q->where('t.id', $only))
            ->when($only === null && $job !== null, fn ($q) => $q->whereNotExists(fn ($s) => $s->from('sys_scheduled_jobs as j')
                ->whereColumn('j.tenant_id', 't.id')->where('j.code', $job)->where('j.paused', true)))
            ->pluck('t.id');
    }
}
