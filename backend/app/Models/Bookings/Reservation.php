<?php

namespace App\Models\Bookings;

use App\Models\Crm\Client;
use App\Models\Finance\Payment;
use App\Models\Finance\Receivable;
use App\Models\Iam\User;
use App\Models\Quotes\Quote;
use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Reserva (spec 04 §3) y su máquina de estados §4.1. */
class Reservation extends TenantModel
{
    use OptimisticLock, SoftDeletes;

    public const DRAFT = 'borrador';

    public const PENDING_AVAILABILITY = 'pendiente_disponibilidad';

    public const CONFIRMED = 'confirmada';

    public const PENDING_PAYMENT = 'pendiente_pago';

    public const PENDING_PROVIDER = 'pendiente_proveedor';

    public const RESCHEDULED = 'reprogramada';

    public const IN_OPERATION = 'en_operacion';

    public const COMPLETED = 'completada';

    public const CANCELLED = 'cancelada';

    public const STATUS_LABELS = [
        self::DRAFT => 'Borrador', self::PENDING_AVAILABILITY => 'Pendiente de disponibilidad', self::CONFIRMED => 'Confirmada',
        self::PENDING_PAYMENT => 'Pendiente de pago', self::PENDING_PROVIDER => 'Pendiente de proveedor', self::RESCHEDULED => 'Reprogramada',
        'parcial' => 'Parcial', 'incidencia' => 'Incidencia', self::IN_OPERATION => 'En operación', self::COMPLETED => 'Completada',
        self::CANCELLED => 'Cancelada',
    ];

    public const CHANNELS = ['Directo', 'Corporativo', 'B2B', 'E-commerce'];

    /** Estados terminales: sin Modificar/Reprogramar/Cancelar ni edición de pasajeros y servicios. */
    public const CLOSED = [self::COMPLETED, self::CANCELLED];

    /** Confirmar reserva (§4.1). */
    public const CONFIRMABLE = [self::DRAFT, self::PENDING_AVAILABILITY, self::PENDING_PROVIDER, self::RESCHEDULED];

    /** RES-BR-08: iniciar operación. */
    public const STARTABLE = [self::CONFIRMED, self::RESCHEDULED, self::PENDING_PAYMENT, self::PENDING_PROVIDER];

    /** Flujo principal mostrado en la Reserva 360. */
    public const FLOW = [self::DRAFT, self::CONFIRMED, self::IN_OPERATION, self::COMPLETED];

    protected $table = 'booking_reservations';

    protected function casts(): array
    {
        return [
            'travel_from' => 'date',
            'travel_to' => 'date',
            'fx_rate' => 'decimal:6',
            'total_amount' => 'decimal:2',
            'penalty_amount' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'quote_version_no' => 'integer',
            'catalog_snapshot' => 'array',
            'policies_snapshot' => 'array',
            'booking' => 'array',
            'b2b_terms' => 'array',
            'rescheduled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED, true);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id')->withTrashed();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'quote_id')->withTrashed();
    }

    public function passengers(): HasMany
    {
        return $this->hasMany(Passenger::class)->orderBy('seq');
    }

    public function services(): HasMany
    {
        return $this->hasMany(BookedService::class)->orderBy('service_date')->orderBy('service_time')->orderBy('code');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(BookingChange::class)->orderByDesc('requested_at');
    }

    public function cancelRequests(): HasMany
    {
        return $this->hasMany(CancelRequest::class)->orderByDesc('requested_at');
    }

    public function cancellation(): HasOne
    {
        return $this->hasOne(Cancellation::class);
    }

    public function receivables(): HasMany
    {
        return $this->hasMany(Receivable::class)->orderBy('due_date')->orderBy('code');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderByDesc('paid_on')->orderByDesc('code');
    }
}
