<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;
use App\Services\Crm\CrmClock;

/** Promoción del producto. */
class ProductPromotion extends TenantModel
{
    public const KINDS = ['Precio promocional', 'Descuento fijo', 'Descuento %', 'Promoción por fecha', 'Descuento por cantidad', 'Promoción por temporada', 'Promoción por canal', 'Código promocional'];

    /** Tipos porcentuales (CAT-BR-27: 0 < valor < 60). */
    public const PERCENT_KINDS = ['Descuento %', 'Promoción por fecha', 'Descuento por cantidad', 'Promoción por temporada', 'Promoción por canal', 'Código promocional'];

    public function effectiveStatus(): string
    {
        return $this->date_to->toDateString() < app(CrmClock::class)->today()->toDateString() ? 'vencida' : $this->status;
    }

    protected $table = 'catalog_product_promotions';

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'date_from' => 'date',
            'date_to' => 'date',
        ];
    }
}
