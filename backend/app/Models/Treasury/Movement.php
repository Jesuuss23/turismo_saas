<?php

namespace App\Models\Treasury;

use App\Models\Bookings\Reservation;
use App\Models\Iam\User;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Movimiento del libro de tesorería (spec 12 §3.2). Los sincronizados llevan `source_key` y no se anulan aquí (TES-BR-03). */
class Movement extends TenantModel
{
    public const TYPES = [
        'collection' => 'Cobro', 'collection_reversal' => 'Reversión de cobro', 'supplier_payment' => 'Pago a proveedor', 'transfer' => 'Transferencia',
        'petty_expense' => 'Gasto de caja chica', 'replenishment' => 'Reposición', 'gateway_settlement' => 'Liquidación de pasarela',
        'gateway_fee' => 'Comisión de pasarela', 'count_adjustment' => 'Ajuste de arqueo', 'other_income' => 'Otro ingreso', 'other_expense' => 'Otro egreso',
    ];

    /** Orígenes sincronizados desde Finanzas: se corrigen en el documento de origen. */
    public const SYNCED = ['payment', 'payment_reversal', 'payout', 'payable'];

    protected $table = 'fin_treasury_movements';

    protected function casts(): array
    {
        return ['movement_date' => 'date', 'amount' => 'decimal:2', 'fx_rate' => 'decimal:6', 'orig_amount' => 'decimal:2', 'orig_fx_rate' => 'decimal:6',
            'voided_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function replenishment(): BelongsTo
    {
        return $this->belongsTo(Replenishment::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(ExpenseReceipt::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function signed(): float
    {
        return ($this->direction === 'in' ? 1 : -1) * (float) $this->amount;
    }

    public function isSynced(): bool
    {
        return in_array($this->source_type, self::SYNCED, true);
    }
}
