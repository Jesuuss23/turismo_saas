<?php

namespace App\Models\Marketing;

use App\Models\Bookings\Reservation;
use App\Models\Channel\EcomOrder;
use App\Models\Crm\Lead;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Conversión atribuida a una campaña (read model, MKT-BR-10). */
class Attribution extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'mkt_attributions';

    protected function casts(): array
    {
        return ['attributed_at' => 'datetime', 'revenue_pen' => 'decimal:2'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class)->withTrashed();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class)->withTrashed();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(EcomOrder::class, 'ecom_order_id');
    }
}
