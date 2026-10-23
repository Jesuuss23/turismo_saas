<?php

namespace App\Services\Crm;

use App\Exceptions\ApiException;
use App\Models\Crm\Activity;
use App\Models\Crm\Client;
use App\Models\Crm\CrmModel;
use App\Models\Crm\Lead;
use App\Models\Crm\Opportunity;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Notify;
use App\Services\SequenceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Actividades comerciales (spec 01 §4.3, PROC-007, CRM-BR-28..32). */
class ActivityService
{
    public const TYPE_LABELS = [
        'llamada' => 'Llamada', 'email' => 'Email', 'whatsapp' => 'WhatsApp', 'reunion' => 'Reunión', 'tarea' => 'Tarea', 'postventa' => 'Postventa',
    ];

    public function __construct(
        private readonly CrmAccess $access,
        private readonly CrmUsers $users,
        private readonly CrmClock $clock,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /**
     * Alta manual (CRM-BR-28, CRM-BR-31).
     *
     * @param  array{type: string, subject: string, description?: ?string, related_type?: ?string, related_id?: ?string, owner_id?: ?string, due_at: string}  $data
     */
    public function create(array $data, User $actor): Activity
    {
        $ownerId = $this->access->ownOnly($actor) ? $actor->id : ($data['owner_id'] ?? $actor->id);
        if (! $this->access->ownOnly($actor)) {
            $this->users->assertAssignable($ownerId, 'owner_id', includeOperations: true);
        }

        [$relatedType, $relatedId] = $this->resolveRelated($data['related_type'] ?? null, $data['related_id'] ?? null, $actor);

        return DB::transaction(fn () => $this->schedule(
            type: $data['type'],
            subject: $data['subject'],
            ownerId: $ownerId,
            dueAt: Carbon::parse($data['due_at'], $this->clock->timezone()),
            relatedType: $relatedType,
            relatedId: $relatedId,
            actor: $actor,
            description: $data['description'] ?? null,
        ));
    }

    /** Alta interna (tomar lead, conversión, próxima actividad, detractor…). Sin verificación de permisos. */
    public function schedule(
        string $type,
        string $subject,
        string $ownerId,
        Carbon $dueAt,
        ?string $relatedType,
        ?string $relatedId,
        ?User $actor,
        ?string $description = null,
        ?string $previousId = null,
    ): Activity {
        $activity = Activity::create([
            'code' => $this->sequences->next('activity'),
            'type' => $type,
            'subject' => Str::limit($subject, 200, ''),
            'description' => $description,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'owner_id' => $ownerId,
            'due_at' => $dueAt,
            'status' => Activity::PENDING,
            'previous_activity_id' => $previousId,
            'created_by' => $actor?->id,
        ]);

        $this->audit->log('Actividad', $activity->code, 'crear', null, self::TYPE_LABELS[$type].' · '.$dueAt->format('d/m/Y H:i'), actor: $actor);

        if ($ownerId !== $actor?->id) {
            // "Push" en el prototipo: hasta que exista la app móvil se entrega por Portal.
            $this->notify->push('actividad.asignada', 'portal', User::findOrFail($ownerId), "Nueva actividad asignada: {$activity->subject}", null, [
                'activity_code' => $activity->code, 'related_type' => 'activity', 'related_id' => $activity->id,
            ]);
        }

        return $activity;
    }

    /**
     * Completar con resultado y próxima actividad opcional (CRM-BR-29/30).
     *
     * @param  array{type?: string, subject: string, due_at: string}|null  $next
     * @return array{activity: Activity, next: ?Activity, lead_contacted: bool}
     */
    public function complete(Activity $activity, string $result, ?array $next, User $actor): array
    {
        $this->ensureActionable($activity, $actor);

        return DB::transaction(function () use ($activity, $result, $next, $actor) {
            $activity->forceFill([
                'status' => Activity::DONE,
                'result' => $result,
                'completed_at' => now(),
                'completed_by' => $actor->id,
            ])->save();

            $this->audit->log('Actividad', $activity->code, 'completar', 'pendiente', Str::limit($result, 80), actor: $actor);

            $nextActivity = null;
            if ($next !== null) {
                $nextActivity = $this->schedule(
                    type: $next['type'] ?? $activity->type,
                    subject: $next['subject'],
                    ownerId: $activity->owner_id,
                    dueAt: Carbon::parse($next['due_at'], $this->clock->timezone()),
                    relatedType: $activity->related_type,
                    relatedId: $activity->related_id,
                    actor: $actor,
                    previousId: $activity->id,
                );
            }

            return ['activity' => $activity, 'next' => $nextActivity, 'lead_contacted' => $this->markLeadContacted($activity, $actor)];
        });
    }

    /** CRM-BR-30: una actividad de contacto completada pasa el lead de `nuevo` a `contactado`. */
    private function markLeadContacted(Activity $activity, User $actor): bool
    {
        if ($activity->related_type !== 'lead' || ! in_array($activity->type, Activity::CONTACT_TYPES, true)) {
            return false;
        }

        $lead = Lead::whereKey($activity->related_id)->lockForUpdate()->first();
        if ($lead === null || $lead->status !== Lead::NEW || $lead->owner_id === null) {
            return false;
        }

        $lead->forceFill(['status' => Lead::CONTACTED, 'first_contact_at' => $lead->first_contact_at ?? now()])->save();
        $this->audit->log('Lead', $lead->code, 'primer_contacto', Lead::NEW, Lead::CONTACTED, actor: $actor);

        return true;
    }

    public function reschedule(Activity $activity, string $dueAt, ?string $reason, User $actor): Activity
    {
        $this->ensureActionable($activity, $actor);
        $due = Carbon::parse($dueAt, $this->clock->timezone());
        // §12-7: se rechaza reprogramar al pasado (el prototipo lo permitía).
        if ($due->isPast()) {
            throw ApiException::invalid('due_at', 'La nueva fecha debe ser posterior a la hora actual');
        }

        return DB::transaction(function () use ($activity, $due, $reason, $actor) {
            $before = $activity->due_at->setTimezone($this->clock->timezone())->format('d/m/Y H:i');
            $activity->forceFill(['due_at' => $due, 'status' => Activity::PENDING])->save();
            $this->audit->log('Actividad', $activity->code, 'reprogramar', $before, $due->format('d/m/Y H:i').($reason ? " · {$reason}" : ''), actor: $actor);

            return $activity;
        });
    }

    public function reassign(Activity $activity, string $ownerId, ?string $note, User $actor): Activity
    {
        $this->access->ensureSupervisor($actor, 'reasignar actividades');
        if ($activity->isDone()) {
            throw ApiException::rule('CRM-BR-31', 'No se puede reasignar una actividad completada');
        }
        $owner = $this->users->assertAssignable($ownerId, 'owner_id', includeOperations: true);
        if ($owner->id === $activity->owner_id) {
            throw ApiException::invalid('owner_id', 'Seleccione un responsable distinto al actual');
        }

        return DB::transaction(function () use ($activity, $owner, $note, $actor) {
            $previous = $activity->owner?->name;
            $activity->forceFill(['owner_id' => $owner->id])->save();
            $this->audit->log('Actividad', $activity->code, 'reasignar', $previous, $owner->name.($note ? " · {$note}" : ''), actor: $actor);
            $this->notify->push('actividad.reasignado', 'portal', $owner, "Actividad {$activity->code} reasignada a su cartera", null, [
                'activity_code' => $activity->code, 'related_type' => 'activity', 'related_id' => $activity->id,
            ]);

            return $activity;
        });
    }

    /** CRM-BR-14: al descartar un lead se cierran sus actividades abiertas. */
    public function autoCloseForLead(Lead $lead): int
    {
        CrmModel::bumpVersion();

        return Activity::where('related_type', 'lead')->where('related_id', $lead->id)
            ->where('status', '<>', Activity::DONE)
            ->update([
                'status' => Activity::DONE,
                'result' => 'Cerrada automáticamente: lead descartado',
                'auto_closed' => true,
                'completed_at' => now(),
                'lock_version' => DB::raw('lock_version + 1'),
                'updated_at' => now(),
            ]);
    }

    /**
     * JOB-04: marca vencidas las pendientes con `due_at < now()`. Devuelve las actividades a
     * recordar (vencidas o del día) agrupadas por owner.
     *
     * @return array<string, list<Activity>>
     */
    public function markOverdue(): array
    {
        CrmModel::bumpVersion();
        Activity::where('status', Activity::PENDING)->where('due_at', '<', now())
            ->update(['status' => Activity::OVERDUE, 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);

        $endOfDay = $this->clock->today()->endOfDay();

        return Activity::where('status', '<>', Activity::DONE)->where('due_at', '<=', $endOfDay)
            ->orderBy('due_at')->get()->groupBy('owner_id')->map->all()->all();
    }

    /** Completar/reprogramar: no completada y propia (Ventas) o supervisor (CRM-BR-31). */
    private function ensureActionable(Activity $activity, User $actor): void
    {
        if ($activity->isDone()) {
            throw ApiException::rule('CRM-BR-29', 'La actividad ya está completada');
        }
        if ($activity->owner_id !== $actor->id && ! $this->access->isSupervisor($actor)) {
            throw CrmAccess::outOfScope();
        }
    }

    /**
     * CRM-BR-28: si hay tipo de relación el registro es obligatorio y visible para el actor.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function resolveRelated(?string $type, ?string $id, User $actor): array
    {
        if ($type === null || $type === '') {
            return [null, null];
        }
        if ($id === null || $id === '') {
            throw ApiException::invalid('related_id', 'Seleccione el registro relacionado');
        }

        $visible = match ($type) {
            'lead' => $this->access->leads(Lead::whereKey($id)->whereNotIn('status', [Lead::DISCARDED]), $actor)->exists(),
            'opportunity' => $this->access->opportunities(Opportunity::whereKey($id), $actor)->exists(),
            'client' => $this->access->clients(Client::whereKey($id), $actor)->exists(),
            // Cotizaciones y reservas: se validan cuando existan sus módulos (spec 03/04).
            default => throw ApiException::invalid('related_type', 'Tipo de relación no disponible todavía'),
        };

        if (! $visible) {
            throw ApiException::invalid('related_id', 'El registro relacionado no existe o no está en su cartera');
        }

        return [$type, $id];
    }
}
