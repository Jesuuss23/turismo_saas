<?php

namespace App\Models\Finance;

use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Línea de extracto bancario (§4.7). */
class BankLine extends TenantModel
{
    use OptimisticLock;

    protected $table = 'fin_bank_lines';

    protected function casts(): array
    {
        return ['value_date' => 'date', 'amount' => 'decimal:2'];
    }

    public const STATUS_LABELS = ['pendiente' => 'Pendiente', 'sugerido' => 'Sugerido', 'excepcion' => 'Excepción', 'conciliado' => 'Conciliado'];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'matched_payment_id');
    }

    public function payable(): BelongsTo
    {
        return $this->belongsTo(Payable::class, 'matched_payable_id');
    }
}
