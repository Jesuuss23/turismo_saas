<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Salida programada (§3.9, §4.4). */
class Departure extends TenantModel
{
    use OptimisticLock;

    public const STATUSES = ['abierta', 'completa', 'cerrada', 'cancelada'];

    protected $table = 'catalog_product_departures';

    protected function casts(): array
    {
        return [
            'departure_date' => 'date',
            'capacity' => 'integer',
            'sold' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'provider_id');
    }

    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class, 'departure_id');
    }
}
