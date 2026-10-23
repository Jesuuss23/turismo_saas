<?php

namespace App\Services;

use App\Models\Sys\Sequence;
use App\Tenancy\MissingTenantContext;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Correlativos (§3.7, SEC-BR-20, equivalente a `Seq.next`). Asignación atómica con
 * SELECT … FOR UPDATE. Para documentos fiscales, llamar a next() dentro de la transacción
 * de confirmación: si hay rollback el número vuelve a quedar libre y no hay huecos.
 */
class SequenceService
{
    /** Semilla del prototipo: doc_type => [label, series, prefix, pad, next, year_reset]. */
    public const DEFAULTS = [
        'quote' => ['Cotización', 'default', 'COT-{Y}-', 5, 192, true],
        'reservation' => ['Reserva', 'default', 'RES-{Y}-', 6, 350, true],
        'ops_order' => ['Orden operativa', 'default', 'OP-{Y}-', 4, 415, true],
        'voucher' => ['Voucher', 'default', 'VOU-{Y}-', 5, 1205, true],
        'invoice' => ['Factura', 'F001', 'F001-', 8, 1484, false],
        'receipt' => ['Boleta', 'B001', 'B001-', 8, 8813, false],
    ];

    public function __construct(private readonly TenantContext $tenant) {}

    public function next(string $docType, string $series = 'default'): string
    {
        if (! $this->tenant->has()) {
            throw new MissingTenantContext;
        }

        return DB::transaction(function () use ($docType, $series) {
            /** @var Sequence|null $seq */
            $seq = Sequence::where('doc_type', $docType)->where('series', $series)->lockForUpdate()->first()
                ?? throw new RuntimeException("No existe correlativo {$docType}/{$series} para el tenant");

            $this->applyYearReset($seq);

            $number = $seq->next_number;
            $seq->next_number = $number + 1;
            $seq->save();

            return $seq->prefix.str_pad((string) $number, $seq->pad_width, '0', STR_PAD_LEFT);
        });
    }

    /** Documentos internos con año en el prefijo reinician al cambiar de año (§12-10, por confirmar). */
    private function applyYearReset(Sequence $seq): void
    {
        $year = now()->year;
        if ($seq->year_reset && preg_match('/(\d{4})/', $seq->prefix, $m) && (int) $m[1] < $year) {
            $seq->prefix = str_replace($m[1], (string) $year, $seq->prefix);
            $seq->next_number = 1;
        }
    }

    /** Crea los correlativos por defecto de un tenant (alta PROC-001). Requiere conexión con permisos de INSERT. */
    public static function seedFor(string $tenantId, string $connection = 'pgsql_admin', ?int $year = null): void
    {
        $year ??= now()->year;
        foreach (self::DEFAULTS as $docType => [$label, $series, $prefix, $pad, $next, $yearReset]) {
            DB::connection($connection)->table('sys_sequences')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'doc_type' => $docType,
                'label' => $label,
                'series' => $series,
                'prefix' => str_replace('{Y}', (string) $year, $prefix),
                'pad_width' => $pad,
                'next_number' => $next,
                'year_reset' => $yearReset,
                'updated_at' => now(),
            ]);
        }
    }
}
