<?php

namespace App\Models\Crm;

use App\Models\Iam\User;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Spec 01 §3.8 y máquina de estados §4.3. */
class Activity extends CrmModel
{
    use OptimisticLock;

    public const PENDING = 'pendiente';

    public const OVERDUE = 'vencida';

    public const DONE = 'completada';

    /** Tipos que cuentan como contacto con el lead (CRM-BR-30). */
    public const CONTACT_TYPES = ['llamada', 'email', 'whatsapp', 'reunion'];

    public const TYPES = ['llamada', 'email', 'whatsapp', 'reunion', 'tarea', 'postventa'];

    public const RELATED_TYPES = ['lead', 'opportunity', 'client', 'quote', 'reservation'];

    protected $table = 'crm_activities';

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'completed_at' => 'datetime', 'auto_closed' => 'boolean'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function isDone(): bool
    {
        return $this->status === self::DONE;
    }

    /** Estado efectivo: la UI deriva "vencida" en tiempo real (§11-28). */
    public function effectiveStatus(): string
    {
        return ! $this->isDone() && $this->due_at->isPast() ? self::OVERDUE : $this->status;
    }
}
