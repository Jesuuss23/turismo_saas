<?php

namespace App\Models\Channel;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Solicitud de liquidación de comisiones de una agencia (spec 08 §3.5, B2B-BR-15). */
class B2bSettlementRequest extends TenantModel
{
    protected $table = 'channel_b2b_settlement_requests';

    public const STATUS_LABELS = ['solicitada' => 'Solicitada', 'aprobada' => 'Aprobada', 'rechazada' => 'Rechazada', 'pagada' => 'Pagada'];

    protected function casts(): array
    {
        return ['amount_pen' => 'decimal:2', 'fx_rate' => 'decimal:6', 'commission_ids' => 'array'];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(B2bAgency::class, 'agency_id');
    }
}
