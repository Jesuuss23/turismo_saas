<?php

namespace App\Services\Bookings;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/** Correlativos de Reservas y de los documentos financieros que crea (spec 04 §3). `reservation` ya viene de SequenceService. */
class BookingProvisioner
{
    /** doc_type => [etiqueta, prefijo, relleno, siguiente]. */
    public const SEQUENCES = [
        'booking_service' => ['Servicio de reserva', 'RS-', 5, 1],
        'receivable' => ['Cuenta por cobrar', 'CXC-', 5, 715],
        'payment' => ['Pago', 'PAG-', 5, 1],
        // §12-4: serie propia de nota de crédito (no la de Factura).
        'credit_note' => ['Nota de crédito', 'FC01-', 8, 1],
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
