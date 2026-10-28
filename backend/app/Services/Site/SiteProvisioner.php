<?php

namespace App\Services\Site;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/** Configuración inicial del sitio público por tenant (spec 15): correlativo de carritos. */
class SiteProvisioner
{
    /** doc_type => [etiqueta, prefijo, relleno, siguiente]. */
    public const SEQUENCES = [
        'web_cart' => ['Carrito web', 'CART-', 5, 1],
    ];

    public static function seedTenant(string $tenantId, ConnectionInterface $db): void
    {
        $now = now();
        foreach (self::SEQUENCES as $docType => [$label, $prefix, $pad, $next]) {
            $db->table('sys_sequences')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'doc_type' => $docType, 'label' => $label, 'series' => 'default',
                'prefix' => $prefix, 'pad_width' => $pad, 'next_number' => $next, 'year_reset' => false, 'updated_at' => $now,
            ]);
        }
    }
}
