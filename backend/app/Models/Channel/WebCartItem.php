<?php

namespace App\Models\Channel;

use App\Models\Catalog\Departure;
use App\Models\Catalog\Hold;
use App\Models\Catalog\Product;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Línea del carrito (spec 15 §3.2): el precio se recalcula en cada lectura (RT-5). */
class WebCartItem extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'channel_web_cart_items';

    protected function casts(): array
    {
        return ['service_date' => 'date', 'options' => 'array', 'extras' => 'array', 'hold_expires_at' => 'datetime', 'added_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id')->withTrashed();
    }

    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class, 'departure_id');
    }

    public function hold(): BelongsTo
    {
        return $this->belongsTo(Hold::class, 'hold_id');
    }

    public function lineCode(): string
    {
        return 'L'.str_pad((string) $this->line_no, 3, '0', STR_PAD_LEFT);
    }
}
