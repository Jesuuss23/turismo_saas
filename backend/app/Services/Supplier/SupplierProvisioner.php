<?php

namespace App\Services\Supplier;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/** Correlativos de la extranet y de las cuentas por pagar (spec 06 §3). */
class SupplierProvisioner
{
    /** doc_type => [etiqueta, prefijo, relleno, siguiente]. */
    public const SEQUENCES = [
        'rate_request' => ['Solicitud de tarifa', 'SOL-TAR-', 4, 101],
        'payable' => ['Cuenta por pagar', 'CXP-', 5, 301],
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
