<?php

namespace App\Models\Ops;

use App\Models\Bookings\Reservation;
use App\Models\Iam\User;
use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Orden operativa (spec 05 §3.1) y su máquina de estados §4.1. */
class Order extends TenantModel
{
    use OptimisticLock, SoftDeletes;

    public const PLANNED = 'planificada';

    public const PENDING_ASSIGNMENT = 'pendiente_asignacion';

    public const ASSIGNED = 'asignada';

    public const CONFIRMED = 'confirmada';

    public const IN_EXECUTION = 'en_ejecucion';

    public const COMPLETED = 'completada';

    public const CANCELLED = 'cancelada';

    public const FLOW = [self::PLANNED, self::PENDING_ASSIGNMENT, self::ASSIGNED, self::CONFIRMED, self::IN_EXECUTION, self::COMPLETED];

    public const STATUS_LABELS = [
        self::PLANNED => 'Planificada', self::PENDING_ASSIGNMENT => 'Pend. asignación', self::ASSIGNED => 'Asignada',
        self::CONFIRMED => 'Confirmada', self::IN_EXECUTION => 'En ejecución', self::COMPLETED => 'Completada', self::CANCELLED => 'Cancelada',
    ];

    /** Estados en los que el recálculo automático (`recomputeOp`) puede mover la orden. */
    public const RECOMPUTABLE = [self::PENDING_ASSIGNMENT, self::ASSIGNED, self::CONFIRMED];

    public const DEFAULT_TASKS = [
        'Enviar solicitudes a proveedores', 'Asignar guías, conductores y vehículos', 'Emitir vouchers de servicios confirmados',
        'Llamada de bienvenida al cliente', 'Encuesta de cierre',
    ];

    protected $table = 'ops_orders';

    protected function casts(): array
    {
        return ['date_from' => 'date', 'date_to' => 'date', 'closed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return ! in_array($this->status, [self::COMPLETED, self::CANCELLED], true);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id')->withTrashed();
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class)->orderBy('service_date')->orderBy('service_time');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(OrderTask::class)->orderBy('position');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class)->orderByDesc('created_at');
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(Evidence::class)->whereNull('removed_at')->orderByDesc('captured_at');
    }
}
