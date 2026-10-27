<?php

namespace App\Services\Inventory;

use App\Services\Admin\AdminProvisioner;
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
        // Los tenants nuevos reciben los jobs con AdminProvisioner; aquí se agregan a los que ya existían.
        AdminProvisioner::ensureSystemJobs($tenantId, $db, self::JOBS, $now);
    }
}
