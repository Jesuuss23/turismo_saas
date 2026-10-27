<?php

namespace App\Models\Crm;

use App\Models\Finance\Commission;
use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Meta comercial por asesor, sede o equipo (spec 14 §3.14, §4.5). */
class SalesTarget extends TenantModel
{
    use OptimisticLock;

    public const STATUS_LABELS = ['borrador' => 'Borrador', 'vigente' => 'Vigente', 'cerrada' => 'Cerrada', 'anulada' => 'Anulada'];

    public const SCOPE_LABELS = ['asesor' => 'Asesor', 'sede' => 'Sede', 'equipo' => 'Equipo'];

    /** Indicadores de la meta: columna => etiqueta. */
    public const GOALS = ['goal_sales_pen' => 'Ventas S/', 'goal_bookings' => 'Reservas', 'goal_margin_pen' => 'Margen S/', 'goal_conversion_pct' => 'Conversión %'];

    protected $table = 'crm_sales_targets';

    protected function casts(): array
    {
        return ['period_from' => 'date', 'period_to' => 'date', 'goal_sales_pen' => 'decimal:2', 'goal_margin_pen' => 'decimal:2', 'goal_conversion_pct' => 'decimal:2', 'bonus_amount' => 'decimal:2', 'goal_bookings' => 'integer', 'result' => 'array'];
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(SalesTargetAdjustment::class, 'target_id')->orderBy('occurred_at');
    }

    public function commission(): BelongsTo
    {
        return $this->belongsTo(Commission::class, 'bonus_commission_id');
    }

    /** @return array<string, float|int|null> */
    public function goals(): array
    {
        return ['sales' => (float) $this->goal_sales_pen, 'bookings' => $this->goal_bookings, 'margin' => $this->goal_margin_pen === null ? null : (float) $this->goal_margin_pen,
            'conversion' => $this->goal_conversion_pct === null ? null : (float) $this->goal_conversion_pct, 'bonus' => $this->bonus_amount === null ? null : (float) $this->bonus_amount];
    }
}
