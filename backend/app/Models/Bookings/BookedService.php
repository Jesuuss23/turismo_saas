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
        self::REJECTED => 'Rechazado', self::CANCELLED => 'Cancelado', self::COMPLETED => 'Completado', 'en_curso' => 'En curso',
    ];

    public const IN_PROGRESS = 'en_curso';

    /** Tipo operativo (spec 05 §3.3): reemplaza la heurística por nombre del prototipo. */
    public const KINDS = ['tour', 'traslado', 'alojamiento', 'tren_entrada', 'libre'];

    protected $table = 'booking_services';

    /** @return array{service_kind: string, requires_assignment: bool} según el tipo del servicio de catálogo. */
    public static function kindFor(?string $catalogType): array
    {
        $kind = match ($catalogType) {
            'Traslado', 'Transporte' => 'traslado',
            'Tour', 'Actividad', 'Guía' => 'tour',
            'Alojamiento' => 'alojamiento',
            'Tren', 'Entrada' => 'tren_entrada',
            default => 'libre',
        };

        return ['service_kind' => $kind, 'requires_assignment' => in_array($kind, ['tour', 'traslado'], true)];
    }

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
            'requires_assignment' => 'boolean',
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

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }
}
