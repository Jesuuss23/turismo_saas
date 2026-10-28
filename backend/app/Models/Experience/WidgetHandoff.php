<?php

namespace App\Models\Experience;

use App\Models\Catalog\Product;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Traspaso del widget al checkout (TTL 24 h, spec 17 §3.8). */
class WidgetHandoff extends TenantModel
{
    protected $table = 'channel_widget_handoffs';

    protected function casts(): array
    {
        return ['service_date' => 'date', 'pax' => 'array', 'addons' => 'array', 'lodging' => 'array', 'removed' => 'array', 'quote_snapshot' => 'array', 'rules_applied' => 'array', 'fx_rate' => 'decimal:6', 'expires_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
