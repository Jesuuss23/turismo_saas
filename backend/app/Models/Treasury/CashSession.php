<?php

namespace App\Models\Treasury;

use App\Models\Iam\User;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sesión diaria de caja chica: open → closed con acta de arqueo de cierre (spec 12 §3.5). */
class CashSession extends TenantModel
{
    protected $table = 'fin_cash_sessions';

    protected function casts(): array
    {
        return ['session_date' => 'date', 'opened_at' => 'datetime', 'closed_at' => 'datetime', 'opening_amount' => 'decimal:2'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function closingCount(): BelongsTo
    {
        return $this->belongsTo(CashCount::class, 'closing_count_id');
    }
}
