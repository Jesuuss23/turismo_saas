<?php

namespace App\Models\Channel;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Carrito del sitio público (spec 15 §3.1, §4.1). */
class WebCart extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'channel_web_carts';

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['utm' => 'array', 'last_activity_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(WebCartItem::class, 'cart_id')->orderBy('line_no');
    }
}
