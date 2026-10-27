<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Precio B2B por agencia (spec 08 §3.3: "Quitar" = soft delete; vencida derivada por valid_to). */
class ProductB2bRate extends TenantModel
{
    use SoftDeletes;

    protected $table = 'catalog_product_b2b_rates';

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'commission_pct' => 'decimal:2',
            'is_credit' => 'boolean',
            'valid_to' => 'date',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
