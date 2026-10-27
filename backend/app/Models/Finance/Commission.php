<?php

namespace App\Models\Finance;

use App\Models\Bookings\Reservation;
use App\Models\Channel\B2bAgency;
use App\Models\Iam\User;
use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Comisión de vendedor o agencia B2B por reserva (PROC-047). */
class Commission extends TenantModel
{
    use OptimisticLock, SoftDeletes;

    protected $table = 'fin_commissions';

    protected function casts(): array
    {
        return ['base_amount' => 'decimal:2', 'pct' => 'decimal:2', 'amount' => 'decimal:2', 'fx_rate' => 'decimal:6', 'approved_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public const STATUS_LABELS = ['calculada' => 'Calculada', 'aprobada' => 'Aprobada', 'pagada' => 'Pagada'];

    public const PAY_METHODS = ['vendedor' => ['Planilla del mes', 'Transferencia BCP'], 'agencia_b2b' => ['Nota de crédito a cuenta B2B', 'Transferencia BCP']];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_user_id');
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(B2bAgency::class, 'beneficiary_agency_id');
    }

    public function beneficiaryName(): string
    {
        return $this->kind === 'agencia_b2b' ? (string) $this->agency?->name : (string) $this->user?->name;
    }
}
