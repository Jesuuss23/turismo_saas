<?php

namespace App\Services\Finance;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/** Configuración inicial de Finanzas por tenant (spec 07 §3): correlativos, series SUNAT, medios de pago, reglas de comisión y agencias B2B. */
class FinanceProvisioner
{
    /** doc_type => [etiqueta, prefijo, relleno, siguiente]. */
    public const SEQUENCES = [
        'payout' => ['Pago a proveedor', 'PPR-', 4, 1001],
        'commission' => ['Comisión', 'COM-', 4, 301],
        'bank_line' => ['Línea de extracto', 'BNK-', 1, 1],
        'reconciliation' => ['Cierre de conciliación', 'CON-', 3, 1],
    ];

    /** Series por tipo de comprobante (FIN-BR-46): serie => [tipo, siguiente correlativo]. */
    public const SERIES = ['F001' => ['01', 1482], 'B001' => ['03', 8811], 'FC01' => ['07', 31], 'BC01' => ['07', 12]];

    /** §3.9: código => [etiqueta, exige referencia, crédito B2B]. */
    public const METHODS = [
        'transferencia_bcp' => ['Transferencia BCP', true, false],
        'transferencia_interbank' => ['Transferencia Interbank', true, false],
        'transferencia_bbva' => ['Transferencia BBVA', true, false],
        'tarjeta' => ['Tarjeta', true, false],
        'yape' => ['Yape', true, false],
        'plin' => ['Plin', true, false],
        'efectivo' => ['Efectivo', false, false],
        'credito_b2b' => ['Crédito B2B', false, true],
    ];

    /** FIN-BR-26 / C10: [beneficiario, condición, etiqueta, %, base]. */
    public const RULES = [
        ['vendedor', 'directo_ecommerce', 'Venta directa / e-commerce: 3 % sobre neto sin IGV', 3.00, 'neto_sin_igv'],
        ['vendedor', 'corporativo_grupos', 'Corporativo / grupos: 2,5 % sobre neto sin IGV', 2.50, 'neto_sin_igv'],
        ['agencia_b2b', 'b2b_convenio', 'Agencia B2B: % del convenio sobre el total', null, 'total'],
    ];

    public static function seedTenant(string $tenantId, ConnectionInterface $db, array $settings = []): void
    {
        $now = now();
        foreach (self::SEQUENCES as $docType => [$label, $prefix, $pad, $next]) {
            $db->table('sys_sequences')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'doc_type' => $docType, 'label' => $label,
                'series' => 'default', 'prefix' => $prefix, 'pad_width' => $pad, 'next_number' => $next, 'year_reset' => false, 'updated_at' => $now,
            ]);
        }
        foreach (self::SERIES as $series => [$type, $next]) {
            $db->table('fin_document_series')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'doc_type' => $type, 'series' => $series,
                'next_number' => $next, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (self::METHODS as $code => [$label, $reference, $b2b]) {
            $db->table('fin_payment_methods')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'code' => $code, 'label' => $label,
                'requires_reference' => $reference, 'is_b2b_credit' => $b2b, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
        if (! $db->table('fin_commission_rules')->where('tenant_id', $tenantId)->exists()) {
            foreach (self::RULES as [$kind, $condition, $label, $pct, $base]) {
                $db->table('fin_commission_rules')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'beneficiary_kind' => $kind,
                    'condition' => $condition, 'label' => $label, 'pct' => $pct, 'base_type' => $base, 'valid_from' => '2026-01-01', 'active' => true,
                    'created_at' => $now, 'updated_at' => $now]);
            }
        }
        // Las agencias B2B vivían en `settings.b2b.agencies` (spec 02); pasan a tabla con línea de crédito usada.
        foreach (data_get($settings, 'b2b.agencies', []) as $a) {
            $db->table('channel_b2b_agencies')->insertOrIgnore([
                'id' => $a['id'] ?? (string) Str::uuid7(), 'tenant_id' => $tenantId, 'code' => $a['code'], 'name' => $a['name'],
                'client_id' => $db->table('crm_clients')->where('tenant_id', $tenantId)->where('b2b_agency_id', $a['id'] ?? null)->value('id'),
                'status' => $a['status'] ?? 'activa', 'credit_limit' => $a['credit_line'] ?? 0, 'credit_used' => 0, 'commission_pct' => $a['commission_pct'] ?? 10,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
