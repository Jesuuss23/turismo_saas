<?php

namespace App\Services\Inventory;

use App\Services\Admin\AdminCatalog;
use App\Services\Admin\CronSchedule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/** Configuración inicial de cupos y documentos de viaje por tenant (spec 11): correlativos y jobs JOB-10/JOB-11. */
class InventoryProvisioner
{
    /** doc_type => [etiqueta, prefijo, relleno, siguiente, reinicio anual]. */
    public const SEQUENCES = [
        'allotment' => ['Cupo', 'CUP-{Y}-', 3, 1, true],
        'allotment_request' => ['Ampliación de cupo', 'AMP-', 4, 11, false],
    ];

    public const JOBS = ['JOB-10', 'JOB-11'];

    public static function seedTenant(string $tenantId, ConnectionInterface $db): void
    {
        $now = now();
        foreach (self::SEQUENCES as $docType => [$label, $prefix, $pad, $next, $yearReset]) {
            $db->table('sys_sequences')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'doc_type' => $docType, 'label' => $label, 'series' => 'default',
                'prefix' => str_replace('{Y}', (string) $now->year, $prefix), 'pad_width' => $pad, 'next_number' => $next, 'year_reset' => $yearReset, 'updated_at' => $now,
            ]);
        }
        // Los tenants nuevos reciben los jobs con AdminProvisioner; aquí se agregan a los que ya existían. Un reporte programado
        // que hubiera tomado JOB-10/11 (los reportes numeran desde JOB-10) pasa al siguiente código libre.
        foreach (self::JOBS as $code) {
            $taken = $db->table('sys_scheduled_jobs')->where('tenant_id', $tenantId)->where('code', $code)->whereNotNull('report_key')->first();
            if ($taken) {
                $max = $db->table('sys_scheduled_jobs')->where('tenant_id', $tenantId)->pluck('code')->map(fn ($c) => (int) Str::after($c, 'JOB-'))->max();
                $db->table('sys_scheduled_jobs')->where('id', $taken->id)->update(['code' => sprintf('JOB-%02d', max($max + 1, 12))]);
            }
            [$name, $cron, $label, $queue] = AdminCatalog::JOBS[$code];
            $db->table('sys_scheduled_jobs')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'code' => $code, 'name' => $name,
                'job_class' => 'App\\Jobs\\Scheduled\\'.Str::studly(Str::slug($name, '_')), 'cron' => $cron, 'cron_label' => $label, 'queue' => $queue,
                'next_run_at' => CronSchedule::next($cron, $now), 'is_system' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
    }
}
