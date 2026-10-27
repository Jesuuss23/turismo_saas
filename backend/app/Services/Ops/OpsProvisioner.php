<?php

namespace App\Services\Ops;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/** Correlativos de Operaciones (spec 05 §3). `ops_order` y `voucher` ya vienen de SequenceService. */
class OpsProvisioner
{
    /** doc_type => [etiqueta, prefijo, relleno, siguiente]. §12-19: serie "Incidencia" (no máx+1). */
    public const SEQUENCES = [
        'incident' => ['Incidencia', 'INC-', 4, 88],
    ];

    public static function seedTenant(string $tenantId, ConnectionInterface $db): void
    {
        foreach (self::SEQUENCES as $docType => [$label, $prefix, $pad, $next]) {
            $db->table('sys_sequences')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'doc_type' => $docType, 'label' => $label,
                'series' => 'default', 'prefix' => $prefix, 'pad_width' => $pad, 'next_number' => $next, 'year_reset' => false,
                'updated_at' => now(),
            ]);
        }
    }
}
