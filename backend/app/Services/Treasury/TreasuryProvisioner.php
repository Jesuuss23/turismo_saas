<?php

namespace App\Services\Treasury;

use App\Services\Admin\AdminProvisioner;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/** Configuración inicial de tesorería y del libro de reclamaciones por tenant (spec 12): correlativos, parámetros y JOB-12..15. */
class TreasuryProvisioner
{
    /** doc_type => [etiqueta, prefijo, relleno, siguiente]. */
    public const SEQUENCES = [
        'treasury_account' => ['Cuenta de tesorería', 'CTA-', 2, 1],
        'treasury_movement' => ['Movimiento de tesorería', 'MOV-', 6, 1],
        'treasury_transfer' => ['Transferencia de tesorería', 'TRF-', 4, 1],
        'cash_session' => ['Sesión de caja chica', 'CJS-', 4, 1],
        'cash_count' => ['Arqueo de caja', 'ARQ-', 4, 1],
        'cash_replenishment' => ['Reposición de caja chica', 'REP-', 4, 1],
        'fx_revaluation' => ['Diferencia cambiaria', 'DC-', 4, 1],
    ];

    public const JOBS = ['JOB-12', 'JOB-13', 'JOB-14', 'JOB-15'];

    public static function seedTenant(string $tenantId, ConnectionInterface $db): void
    {
        $now = now();
        foreach (self::SEQUENCES as $docType => [$label, $prefix, $pad, $next]) {
            $db->table('sys_sequences')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'doc_type' => $docType, 'label' => $label, 'series' => 'default',
                'prefix' => $prefix, 'pad_width' => $pad, 'next_number' => $next, 'year_reset' => false, 'updated_at' => $now,
            ]);
        }
        $db->table('fin_treasury_settings')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'created_at' => $now, 'updated_at' => $now]);
        $db->table('crm_claim_book_settings')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'created_at' => $now, 'updated_at' => $now]);
        AdminProvisioner::ensureSystemJobs($tenantId, $db, self::JOBS, $now);
    }
}
