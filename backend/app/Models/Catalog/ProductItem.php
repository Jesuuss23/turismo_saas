<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Componente del producto: servicio o producto (paquetes, §9/§10). */
class ProductItem extends TenantModel
{
    protected $table = 'catalog_product_items';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_cost_override' => 'decimal:2',
            'is_required' => 'boolean',
            'is_included' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(SupplierService::class, 'service_id');
    }

    public function componentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
