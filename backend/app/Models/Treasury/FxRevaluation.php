<?php

namespace App\Models\Treasury;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Diferencia cambiaria no realizada de una cuenta USD por periodo (spec 12 §3.8, TES-BR-16). */
class FxRevaluation extends TenantModel
{
    protected $table = 'fin_fx_revaluations';

    protected function casts(): array
    {
        return ['revaluation_date' => 'date', 'balance_usd' => 'decimal:2', 'book_rate' => 'decimal:6', 'close_rate' => 'decimal:6',
            'book_value_pen' => 'decimal:2', 'diff_pen' => 'decimal:2'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
