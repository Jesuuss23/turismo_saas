<?php

namespace App\Models\Channel;

use App\Models\Bookings\Reservation;
use App\Models\Catalog\Departure;
use App\Models\Catalog\Product;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Línea del pedido web (spec 15 §3.4): snapshot de precio; `cost_total` nunca se expone al comprador. */
class EcomOrderLine extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'channel_ecom_order_lines';

    protected $hidden = ['cost_total'];

    protected function casts(): array
    {
        return ['service_date' => 'date', 'config_snapshot' => 'array', 'extras_snapshot' => 'array'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id')->withTrashed();
    }

    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class, 'departure_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id')->withTrashed();
    }
}
