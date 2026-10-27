<?php

namespace App\Models\Finance;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pago a proveedor sobre una CxP (PROC-046). */
class Payout extends TenantModel
{
    protected $table = 'fin_payable_payouts';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'fx_rate' => 'decimal:6', 'paid_on' => 'date'];
    }

    public const METHODS = ['transferencia' => 'Transferencia', 'transferencia_cci' => 'Transferencia CCI', 'cheque_gerencia' => 'Cheque de gerencia',
        'efectivo_caja_chica' => 'Efectivo (caja chica)', 'cargo_bancario' => 'Cargo bancario'];

    public function payable(): BelongsTo
    {
        return $this->belongsTo(Payable::class);
    }
}
