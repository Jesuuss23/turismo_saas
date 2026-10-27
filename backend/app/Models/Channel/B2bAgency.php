<?php

namespace App\Models\Channel;

use App\Models\Crm\Client;
use App\Models\TenantModel;
use App\Services\Crm\CrmClock;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Agencia asociada B2B (spec 08 §3.1): convenio, lista de precios, línea de crédito y bloqueo. */
class B2bAgency extends TenantModel
{
    use OptimisticLock, SoftDeletes;

    protected $table = 'channel_b2b_agencies';

    public const STATUS_LABELS = ['activa' => 'Activa', 'bloqueada' => 'Bloqueada', 'inactiva' => 'Inactiva', 'vencido' => 'Convenio vencido'];

    protected function casts(): array
    {
        return ['credit_limit' => 'decimal:2', 'credit_used' => 'decimal:2', 'commission_pct' => 'decimal:2', 'valid_from' => 'date', 'valid_to' => 'date'];
    }

    public function available(): string
    {
        return number_format(max(0, (float) $this->credit_limit - (float) $this->credit_used), 2, '.', '');
    }

    /** §4.1: vencido si valid_to < hoy; si no, bloqueada si excede la línea; si no, el estado persistido. */
    public function effectiveStatus(): string
    {
        if ($this->valid_to && $this->valid_to->toDateString() < app(CrmClock::class)->today()->toDateString()) {
            return 'vencido';
        }
        if ((float) $this->credit_limit > 0 && (float) $this->credit_used > (float) $this->credit_limit) {
            return 'bloqueada';
        }

        return $this->status;
    }

    public function exceeded(): bool
    {
        return (float) $this->credit_limit > 0 && (float) $this->credit_used > (float) $this->credit_limit + 0.004;
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(B2bPriceList::class, 'price_list_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
