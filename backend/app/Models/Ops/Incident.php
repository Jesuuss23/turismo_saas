<?php

namespace App\Models\Ops;

use App\Models\Bookings\BookedService;
use App\Models\Bookings\Reservation;
use App\Models\Iam\User;
use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Incidencia (§3.11, §4.5) con SLA por severidad (OPS-BR-60). */
class Incident extends TenantModel
{
    use OptimisticLock;

    public const OPEN = 'abierta';

    public const IN_PROGRESS = 'en_proceso';

    public const RESOLVED = 'resuelta';

    public const CLOSED = 'cerrada';

    public const ACTIVE = [self::OPEN, self::IN_PROGRESS];

    public const STATUS_LABELS = [self::OPEN => 'Abierta', self::IN_PROGRESS => 'En proceso', self::RESOLVED => 'Resuelta', self::CLOSED => 'Cerrada'];

    public const TYPES = ['Transporte', 'Proveedor', 'Salud del pasajero', 'Horario', 'Clima', 'Documentación', 'Equipaje', 'Seguridad', 'Capacidad', 'Pasajero', 'Otro'];

    public const SEVERITY_LABELS = ['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta', 'critica' => 'Crítica'];

    /** OPS-BR-60: horas corridas (§12-12). */
    public const SLA_HOURS = ['critica' => 4, 'alta' => 8, 'media' => 24, 'baja' => 72];

    public const FLOW = ['Detectar', 'Registrar', 'Clasificar', 'Priorizar', 'Asignar', 'Resolver', 'Evidencia', 'Cerrar'];

    protected $table = 'ops_incidents';

    protected function casts(): array
    {
        return [
            'sla_due_at' => 'datetime', 'sla_breached_at' => 'datetime', 'resolved_at' => 'datetime', 'closed_at' => 'datetime', 'sla_hours' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id')->withTrashed();
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id')->withTrashed();
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(BookedService::class, 'reservation_service_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(IncidentLog::class)->orderBy('created_at')->orderBy('id');
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(Evidence::class)->whereNull('removed_at')->orderByDesc('captured_at');
    }
}
