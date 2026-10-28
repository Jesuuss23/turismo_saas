<?php

namespace App\Domain\Bookings;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/** Spec 16 §3.1: copia el catálogo de plataforma de reglas al tenant y crea los correlativos de rangos y adicionales. */
class BookingRulesProvisioner
{
    /** doc_type => [etiqueta, prefijo, relleno, siguiente]. */
    public const SEQUENCES = [
        'availability_range' => ['Rango de disponibilidad', 'RG-', 4, 1101],
        'product_addon' => ['Adicional de producto', 'ADD-', 4, 1101],
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
        $templates = $db->table('platform_booking_rule_templates')->orderBy('sort_order')->get();
        foreach ($templates as $t) {
            $db->table('sys_booking_rules')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'rule_key' => $t->rule_key, 'group_name' => $t->group_name, 'name' => $t->name,
                'description' => $t->description, 'enabled' => $t->enabled, 'params' => $t->params, 'scope' => $t->scope, 'product_override' => $t->product_override,
                'is_system' => true, 'params_schema' => $t->params_schema, 'sort_order' => $t->sort_order, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
