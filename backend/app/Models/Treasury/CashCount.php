<?php

namespace App\Models\Treasury;

use App\Models\Iam\User;
use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Acta de arqueo (spec 12 §3.6): signed → validated; con diferencia mayor a la tolerancia, pending_approval → approved → validated. */
class CashCount extends TenantModel
{
    use OptimisticLock;

    /** Denominaciones PEN aceptadas (billetes y monedas). */
    public const DENOMINATIONS = ['200', '100', '50', '20', '10', '5', '2', '1', '0.5', '0.2', '0.1'];

    public const STATUS_LABELS = ['signed' => 'Firmada', 'pending_approval' => 'Pendiente de aprobación', 'approved' => 'Aprobada', 'validated' => 'Validada'];

    public const RESULTS = ['balanced' => 'Cuadrado', 'over' => 'Sobrante', 'short' => 'Faltante'];

    protected $table = 'fin_cash_counts';

    protected function casts(): array
    {
        return ['denominations' => 'array', 'counted_at' => 'datetime', 'signed_at' => 'datetime', 'approved_at' => 'datetime', 'validated_at' => 'datetime',
            'counted_amount' => 'decimal:2', 'expected_amount' => 'decimal:2', 'difference' => 'decimal:2'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function witness(): BelongsTo
    {
        return $this->belongsTo(User::class, 'witness_id');
    }
}
