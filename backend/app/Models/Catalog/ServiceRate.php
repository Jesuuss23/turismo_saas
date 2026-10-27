<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nueva vigencia de costo de un servicio de proveedor; se aplica al servicio al llegar `valid_from`. */
class ServiceRate extends TenantModel
{
    protected $table = 'catalog_service_rates';

    protected function casts(): array
    {
        return ['cost' => 'decimal:2', 'valid_from' => 'date', 'applied_at' => 'datetime'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(SupplierService::class, 'service_id')->withTrashed();
    }
}
