<?php

namespace App\Services\Channels;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/** Configuración inicial de Canales por tenant (spec 08 §3): correlativos y listas de precios B2B. */
class ChannelProvisioner
{
    /** doc_type => [etiqueta, prefijo, relleno, siguiente, reinicio anual]. */
    public const SEQUENCES = [
        'b2b_agency' => ['Agencia B2B', 'AGB-', 2, 4, false],
        'b2b_settlement' => ['Liquidación de comisiones B2B', 'LIQ-{Y}-', 5, 1, true],
        'ecom_order' => ['Pedido web', 'WEB-', 5, 10434, false],
        'cms_content' => ['Contenido CMS', 'CMS-', 2, 1, false],
        'support_ticket' => ['Ticket de portal', 'TCK-', 4, 1, false],
        'pax_feedback' => ['Comentario del pasajero', 'FBK-', 4, 1, false],
    ];

    /** §3.2: listas del prototipo. */
    public const PRICE_LISTS = ['Mayorista 2026', 'Minorista 2026', 'Corporativo 2026'];

    /** Medios de cobro de los canales (§3.15 `fin_payments.method`). */
    public const METHODS = ['tarjeta_ecommerce' => 'Tarjeta (e-commerce)', 'tarjeta_portal' => 'Tarjeta (portal cliente)'];

    public static function seedTenant(string $tenantId, ConnectionInterface $db): void
    {
        $now = now();
        foreach (self::METHODS as $code => $label) {
            $db->table('fin_payment_methods')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'code' => $code, 'label' => $label,
                'requires_reference' => true, 'is_b2b_credit' => false, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (self::SEQUENCES as $docType => [$label, $prefix, $pad, $next, $yearReset]) {
            $db->table('sys_sequences')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'doc_type' => $docType, 'label' => $label, 'series' => 'default',
                'prefix' => str_replace('{Y}', (string) $now->year, $prefix), 'pad_width' => $pad, 'next_number' => $next, 'year_reset' => $yearReset, 'updated_at' => $now,
            ]);
        }
        foreach (self::PRICE_LISTS as $name) {
            $db->table('channel_b2b_price_lists')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'name' => $name,
                'year' => 2026, 'status' => 'activa', 'created_at' => $now, 'updated_at' => $now]);
        }
    }
}
