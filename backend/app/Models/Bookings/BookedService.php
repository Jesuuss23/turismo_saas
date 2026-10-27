<?php

namespace App\Models\Bookings;

use App\Models\Catalog\Product;
use App\Models\Catalog\Provider;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Servicio de la reserva por componente del producto (§4.2, §4.3 paso 10). */
class BookedService extends TenantModel
{
    public const PENDING = 'pendiente';

    public const REQUESTED = 'solicitado';

    public const CONFIRMED = 'confirmado';

    public const REJECTED = 'rechazado';

    public const CANCELLED = 'cancelado';

    public const COMPLETED = 'completado';

    /** Servicios activos: cuentan para disponibilidad, penalidad y reprogramación. */
    public const INACTIVE = [self::CANCELLED, self::COMPLETED, self::REJECTED];

    public const STATUS_LABELS = [
        self::PENDING => 'Pendiente', self::REQUESTED => 'Solicitado', self::CONFIRMED => 'Confirmado',
        self::REJECTED => 'Rechazado', self::CANCELLED => 'Cancelado', self::COMPLETED => 'Completado',
    ];

    protected $table = 'booking_services';

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'pax' => 'integer',
            'cost_amount' => 'decimal:2',
            'price_amount' => 'decimal:2',
            'is_required' => 'boolean',
            'requested_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return ! in_array($this->status, self::INACTIVE, true);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id')->withTrashed();
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'provider_id')->withTrashed();
    }
}
