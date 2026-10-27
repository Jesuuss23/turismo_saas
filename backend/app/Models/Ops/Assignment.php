<?php

namespace App\Models\Ops;

use App\Models\Bookings\BookedService;
use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Asignación de recursos por servicio (§3.5) y su máquina de estados §4.2. */
class Assignment extends TenantModel
{
    use OptimisticLock;

    public const PENDING = 'pendiente';

    public const ASSIGNED = 'asignada';

    public const CONFIRMED = 'confirmada';

    public const IN_PROGRESS = 'en_curso';

    public const COMPLETED = 'completada';

    public const STATUS_LABELS = [
        self::PENDING => 'Pendiente', self::ASSIGNED => 'Asignada', self::CONFIRMED => 'Confirmada',
        self::IN_PROGRESS => 'En curso', self::COMPLETED => 'Completada',
    ];

    /** Confirmada por el recurso o más avanzada (OPS-BR-05). */
    public const CONFIRMED_OR_LATER = [self::CONFIRMED, self::IN_PROGRESS, self::COMPLETED];

    protected $table = 'ops_assignments';

    protected function casts(): array
    {
        return [
            'service_date' => 'date', 'confirmed_at' => 'datetime', 'started_at' => 'datetime', 'first_checkin_at' => 'datetime',
            'finished_at' => 'datetime', 'warnings_ack' => 'array', 'no_show_passenger_ids' => 'array',
        ];
    }

    public function hasResource(): bool
    {
        return $this->guide_id || $this->driver_id || $this->vehicle_id;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id')->withTrashed();
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(BookedService::class, 'reservation_service_id');
    }

    public function guide(): BelongsTo
    {
        return $this->belongsTo(Guide::class, 'guide_id')->withTrashed();
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id')->withTrashed();
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id')->withTrashed();
    }

    public function checkins(): HasMany
    {
        return $this->hasMany(Checkin::class)->whereNull('undone_at');
    }
}
