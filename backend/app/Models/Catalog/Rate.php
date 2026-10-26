<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;
use App\Services\Crm\CrmClock;
use App\Support\Concerns\OptimisticLock;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tarifa (PROC-017, §4.3). */
class Rate extends TenantModel
{
    use OptimisticLock;

    public const TYPES = ['Proveedor', 'Costo', 'Venta', 'Retail', 'B2B', 'Mayorista', 'Corporativa', 'VIP', 'Promocional', 'Especial', 'Grupal'];

    public const SEGMENTS = ['Adulto', 'Niño', 'Infante', 'Pasajero', 'Grupo'];

    public const CHANNELS = ['Directo', 'B2B', 'Corporativo', 'E-commerce', 'Mayorista', 'Todos'];

    public const BASES = ['Por persona', 'Precio fijo', 'Por grupo', 'Por habitación', 'Por unidad'];

    /** Tarifas netas (§7.2): solo las ve quien tiene profit, rates o channels.b2b.manage. */
    public const NET_TYPES = ['Proveedor', 'Costo', 'B2B', 'Mayorista'];

    public const NET_CHANNELS = ['B2B', 'Mayorista'];

    /** Estado efectivo (§4.3): inactiva → vencida → programada → vigente. */
    public function effectiveStatus(?CarbonInterface $on = null): string
    {
        $on ??= app(CrmClock::class)->today();

        return match (true) {
            $this->status === 'inactiva' => 'inactiva',
            $this->valid_to->toDateString() < $on->toDateString() => 'vencida',
            $this->valid_from->toDateString() > $on->toDateString() => 'programada',
            default => 'vigente',
        };
    }

    public function isNet(): bool
    {
        return in_array($this->rate_type, self::NET_TYPES, true) || in_array($this->channel, self::NET_CHANNELS, true);
    }

    protected $table = 'catalog_rates';

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'price' => 'decimal:2',
            'min_price' => 'decimal:2',
            'max_price' => 'decimal:2',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class, 'season_id');
    }
}
