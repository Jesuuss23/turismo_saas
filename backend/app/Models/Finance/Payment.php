<?php

namespace App\Models\Finance;

use App\Models\Bookings\Reservation;
use App\Models\Crm\Client;
use App\Models\Iam\User;
use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pago de cliente o reembolso (PROC-042/043). */
class Payment extends TenantModel
{
    use OptimisticLock;

    /** Pagos que cuentan como cobrados (§12-2). */
    public const COUNTED = ['aplicado', 'confirmado'];

    public const STATUS_LABELS = ['pendiente' => 'Pendiente', 'confirmado' => 'Confirmado', 'aplicado' => 'Aplicado', 'rechazado' => 'Rechazado', 'revertido' => 'Revertido'];

    protected $table = 'fin_payments';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'fx_rate' => 'decimal:6', 'paid_on' => 'date', 'reverted_at' => 'datetime'];
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class, 'receivable_id')->withTrashed();
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id')->withTrashed();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id')->withTrashed();
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
