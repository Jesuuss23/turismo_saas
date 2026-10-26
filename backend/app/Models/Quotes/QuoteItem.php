<?php

namespace App\Models\Quotes;

use App\Models\Catalog\Product;
use App\Models\Catalog\Rate;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Línea de una versión (§3 quote_items). Los totales los calcula PostgreSQL. */
class QuoteItem extends TenantModel
{
    public const SEGMENTS = ['Adulto', 'Niño', 'Infante', 'Grupo', 'Pasajero'];

    protected $table = 'quote_items';

    protected array $generated = ['cost_total', 'sale_total'];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'service_date' => 'date',
            'qty' => 'integer',
            'pax' => 'integer',
            'unit_cost' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'cost_total' => 'decimal:2',
            'sale_total' => 'decimal:2',
            'season_factor' => 'decimal:3',
            'rate_snapshot' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(Rate::class);
    }
}
