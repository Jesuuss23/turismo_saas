<?php

namespace App\Models\Channel;

use App\Models\Bookings\Reservation;
use App\Models\Finance\Payment;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Libro inmutable de la línea de crédito B2B (spec 08 §3.4, B2B-BR-12). */
class B2bCreditMovement extends TenantModel
{
    public const UPDATED_AT = null;

    public const AUDIT_COLUMNS = ['created_by'];

    public const TYPE_LABELS = ['cargo_reserva' => 'Cargo por reserva', 'pago_credito' => 'Cobro con Crédito B2B', 'pago_aplicado' => 'Pago aplicado', 'reversion_pago' => 'Reversión de pago',
        'anulacion_reserva' => 'Anulación de reserva', 'ajuste' => 'Ajuste'];

    protected $table = 'channel_b2b_credit_movements';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'source_amount' => 'decimal:2', 'fx_rate' => 'decimal:6', 'balance_after' => 'decimal:2'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id')->withTrashed();
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
