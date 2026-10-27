<?php

namespace App\Models\Treasury;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Transferencia entre cuentas, depósito de efectivo, reposición pagada o liquidación de pasarela (spec 12 §3.4). */
class Transfer extends TenantModel
{
    public const KINDS = ['transfer' => 'Transferencia', 'replenishment' => 'Reposición', 'gateway_settlement' => 'Liquidación de pasarela', 'cash_deposit' => 'Depósito de efectivo'];

    protected $table = 'fin_treasury_transfers';

    protected function casts(): array
    {
        return ['transfer_date' => 'date', 'amount_from' => 'decimal:2', 'amount_to' => 'decimal:2', 'deal_rate' => 'decimal:6', 'fee_amount' => 'decimal:2'];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class);
    }
}
