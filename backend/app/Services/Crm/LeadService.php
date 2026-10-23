<?php

namespace App\Services\Crm;

use App\Exceptions\ApiException;
use App\Models\Crm\Activity;
use App\Models\Crm\Client;
use App\Models\Crm\CrmModel;
use App\Models\Crm\Lead;
use App\Models\Crm\LeadQualification;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Notify;
use App\Services\SequenceService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Captación, asignación y ciclo de vida del lead (spec 01 §4.1, PROC-004/005). */
class LeadService
{
    /** Campos editables del lead (alta y PATCH). */
    public const FIELDS = [
        'full_name', 'company', 'email', 'phone', 'channel_code', 'source_code', 'interest_code',
        'destination_id', 'destination_label', 'product_id', 'travel_date', 'pax', 'notes',
    ];

    /** Campos que una fusión completa si están vacíos en el destino. */
    private const MERGE_FILL = ['company', 'email', 'phone', 'destination_id', 'destination_label', 'product_id', 'travel_date'];

    public function __construct(
        private readonly CrmAccess $access,
        private readonly CrmCatalog $catalog,
        private readonly CrmUsers $users,
        private readonly CrmClock $clock,
        private readonly LeadDuplicateFinder $duplicates,
        private readonly ActivityService $activities,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /** CRM-BR-07. */
    public static function initialScore(array $data): int
    {
        $score = 25
            + (! empty($data['email']) ? 8 : 0)
            + (! empty($data['phone']) ? 8 : 0)
            + (! empty($data['travel_date']) ? 10 : 0)
            + ((int) ($data['pax'] ?? 0) > 0 ? 5 : 0)
            + (! empty($data['product_id']) ? 8 : 0)
            + (! empty($data['company']) ? 4 : 0)
            + (in_array($data['source_code'] ?? null, ['referido', 'llamada_entrante'], true) ? 6 : 0);

        return min(70, $score);
    }

    /**
     * Alta (CRM-BR-01..07). Con coincidencias exige `duplicate_resolution` (CRM-BR-04).
     * `$actor` null = ingreso de sistema (§12-22).
     *
     * @return array{lead: Lead, merged: bool, duplicates: list<array>}
     */
    public function create(array $data, ?User $actor, ?string $resolution = null, ?string $mergeInto = null): array
    {
        $this->assertCatalogs($data);
        $ownerId = $this->resolveOwnerForCreate($data['owner_id'] ?? null, $actor);
        $matches = $this->duplicates->find($data['email'] ?? null, $data['phone'] ?? null);

        if ($matches !== [] && $resolution === null) {
            throw ApiException::rule('CRM-BR-04', 'Se encontraron coincidencias: elija fusionar, marcar como duplicado o crear de todas formas', [
                'duplicates' => $matches,
            ]);
        }

        if ($matches !== [] && $resolution === 'merge') {
            return ['lead' => $this->mergeIncoming($data, $matches, $mergeInto, $actor), 'merged' => true, 'duplicates' => $matches];
        }

        $first = $matches[0] ?? null;
        $notes = $data['notes'] ?? null;
        $status = Lead::NEW;
        $duplicateOf = [null, null];

        if ($first !== null && $resolution === 'mark_duplicate') {
            $status = Lead::DUPLICATE;
            $duplicateOf = [$first['type'], $first['id']];
            $notes = trim('Coincide con '.LeadDuplicateFinder::describe($first)."\n".$notes);
        } elseif ($first !== null) {
            $notes = trim('Registrado pese a coincidencia con '.LeadDuplicateFinder::describe($first)."\n".$notes);
        }

        $lead = DB::transaction(function () use ($data, $actor, $ownerId, $status, $duplicateOf, $notes) {
            $lead = Lead::create(['notes' => $notes] + Arr::only($data, self::FIELDS) + [
                'code' => $this->sequences->next('lead'),
                'pax' => (int) ($data['pax'] ?? 0),
                'status' => $status,
                'owner_id' => $ownerId,
                'assigned_at' => $ownerId ? now() : null,
                'score' => self::initialScore($data),
                'duplicate_of_type' => $duplicateOf[0],
                'duplicate_of_id' => $duplicateOf[1],
                'validated_at' => now(),
                'created_by' => $actor?->id,
            ]);

            $after = Lead::STATUS_LABELS[$status].($status === Lead::DUPLICATE ? ' · duplicado de '.$this->duplicateCode($lead) : '');
            $this->audit->log('Lead', $lead->code, 'crear', $this->catalog->label('lead_source', $lead->source_code), $after, actor: $actor, system: $actor === null);

            if ($ownerId !== null) {
                $owner = User::findOrFail($ownerId);
                $this->audit->log('Lead', $lead->code, 'asignar', 'sin asignar', $owner->name, actor: $actor);
                if ($ownerId !== $actor?->id) {
                    $this->notify->push('lead.asignado', 'portal', $owner, "Nuevo lead asignado: {$lead->full_name} ({$lead->code})", null, $this->ref($lead));
                }
            }

            return $lead;
        });

        return ['lead' => $lead->refresh(), 'merged' => false, 'duplicates' => $matches];
    }

    public function update(Lead $lead, array $data, User $actor): array
    {
        $this->access->ensureLeadManageable($lead, $actor);
        if (in_array($lead->status, [Lead::CONVERTED, Lead::DISCARDED], true)) {
            throw ApiException::rule('CRM-BR-13', 'Un lead convertido o descartado no se puede editar');
        }
        $this->assertCatalogs($data + $lead->only(['channel_code', 'source_code', 'interest_code']));

        $lead->fill(Arr::only($data, self::FIELDS));
        if (! $lead->email && ! $lead->phone) {
            throw ApiException::invalid('email', 'Validación PROC-004: registre al menos correo o teléfono.');
        }
        $changed = array_keys($lead->getDirty());
        if ($changed === []) {
            return ['lead' => $lead, 'duplicates' => []];
        }

        DB::transaction(function () use ($lead, $changed, $actor) {
            $lead->save();
            $this->audit->log('Lead', $lead->code, 'editar', implode(', ', $changed), 'actualizado', actor: $actor);
        });

        $lead->refresh();

        return ['lead' => $lead, 'duplicates' => $this->duplicates->find($lead->email, $lead->phone, $lead->id)];
    }

    /** "Tomar lead" de la cola (CRM-BR-08): UPDATE condicional atómico. */
    public function take(Lead $lead, User $actor): Lead
    {
        if (in_array($lead->status, [Lead::CONVERTED, Lead::DISCARDED], true)) {
            throw ApiException::rule('CRM-BR-08', 'Solo se pueden tomar leads abiertos de la cola comercial');
        }

        return DB::transaction(function () use ($lead, $actor) {
            $taken = Lead::whereKey($lead->id)->whereNull('owner_id')->whereNotIn('status', [Lead::CONVERTED, Lead::DISCARDED])
                ->update([
                    'owner_id' => $actor->id,
                    'taken_by' => $actor->id,
                    'assigned_at' => now(),
                    'updated_by' => $actor->id,
                    'updated_at' => now(),
                    'lock_version' => DB::raw('lock_version + 1'),
                ]);
            if ($taken === 0) {
                throw ApiException::rule('CRM-BR-08', 'Otro asesor tomó el lead antes que usted');
            }

            $lead->refresh();
            CrmModel::bumpVersion();
            $this->audit->log('Lead', $lead->code, 'tomar_lead', 'sin asignar (cola comercial)', $actor->name, actor: $actor);
            $this->activities->schedule('llamada', "Primer contacto — {$lead->full_name}", $actor->id, $this->clock->firstContactDue(), 'lead', $lead->id, $actor);

            foreach ($this->users->managers() as $manager) {
                if ($manager->id !== $actor->id) {
                    $this->notify->push('lead.tomado', 'portal', $manager, "{$actor->name} tomó el lead {$lead->code} ({$lead->full_name})", null, $this->ref($lead));
                }
            }

            return $lead;
        });
    }

    /** Asignar / reasignar responsable (CRM-BR-09). */
    public function assign(Lead $lead, string $ownerId, bool $scheduleFirstContact, ?string $note, User $actor): Lead
    {
        $this->access->ensureSupervisor($actor, 'asignar o reasignar leads');
        if (! $lead->isOpen()) {
            throw ApiException::rule('CRM-BR-09', 'Solo se asignan leads abiertos');
        }
        $owner = $this->users->assertAssignable($ownerId);
        if ($owner->id === $lead->owner_id) {
            throw ApiException::invalid('owner_id', 'Seleccione un asesor distinto al actual');
        }

        return DB::transaction(function () use ($lead, $owner, $scheduleFirstContact, $note, $actor) {
            $previous = $lead->owner?->name;
            $lead->forceFill(['owner_id' => $owner->id, 'assigned_at' => now()])->save();

            $action = $previous === null ? 'asignar' : 'reasignar';
            $this->audit->log('Lead', $lead->code, $action, $previous ?? 'sin asignar', $owner->name.($note ? " · {$note}" : ''), actor: $actor);

            if ($owner->id !== $actor->id) {
                [$event, $subject] = $previous === null
                    ? ['lead.asignado', "Lead asignado: {$lead->full_name} ({$lead->code})"]
                    : ['lead.reasignado', "Lead {$lead->code} reasignado a su cartera"];
                $this->notify->push($event, 'portal', $owner, $subject, null, $this->ref($lead));
            }

            if ($scheduleFirstContact) {
                $this->activities->schedule('llamada', "Primer contacto — {$lead->full_name}", $owner->id, $this->clock->firstContactDue(), 'lead', $lead->id, $actor);
            }

            return $lead;
        });
    }

    /** "Cambiar estado" entre nuevo/contactado/calificado (CRM-BR-10/11/13). */
    public function changeStatus(Lead $lead, string $status, ?string $note, User $actor): Lead
    {
        $this->access->ensureLeadManageable($lead, $actor);
        $this->ensureOpen($lead);
        if ($status === Lead::QUALIFIED && $lead->score < Lead::QUALIFY_THRESHOLD) {
            throw ApiException::rule('CRM-BR-10', 'PROC-005: para marcar como calificado el score debe ser ≥ 50');
        }
        if ($status !== Lead::NEW && $lead->owner_id === null) {
            throw ApiException::rule('CRM-BR-11', 'PROC-004: asigne un responsable antes de avanzar el lead');
        }
        if ($status === $lead->status) {
            return $lead;
        }

        return DB::transaction(function () use ($lead, $status, $note, $actor) {
            $before = $lead->status;
            $lead->status = $status;
            if ($status === Lead::CONTACTED && $lead->first_contact_at === null) {
                $lead->first_contact_at = now();
            }
            $lead->save();
            $this->audit->log('Lead', $lead->code, 'cambio_estado', $before, $status.($note ? " · {$note}" : ''), actor: $actor);

            return $lead;
        });
    }

    /**
     * Calificación BANT (PROC-005). Inserta una fila de historial; con `mark_qualified` y score ≥ 50 pasa a calificado.
     *
     * @return array{lead: Lead, qualification: LeadQualification, reached_threshold: bool}
     */
    public function qualify(Lead $lead, array $data, User $actor): array
    {
        $this->access->ensureLeadManageable($lead, $actor);
        $this->ensureOpen($lead);

        return DB::transaction(function () use ($lead, $data, $actor) {
            $qualification = LeadQualification::create(Arr::only($data, ['budget', 'authority', 'need', 'timeline', 'declared_amount', 'currency', 'notes']) + [
                'lead_id' => $lead->id,
                'qualified_by' => $actor->id,
                'qualified_at' => now(),
            ]);
            $score = $data['budget'] + $data['authority'] + $data['need'] + $data['timeline'];
            $reached = $score >= Lead::QUALIFY_THRESHOLD;
            $before = "{$lead->score} / {$lead->status}";

            $lead->score = $score;
            if ($reached && ! empty($data['mark_qualified'])) {
                $lead->status = Lead::QUALIFIED;
                // §12-19: un supervisor que califica un lead sin responsable queda como responsable.
                $lead->owner_id ??= $actor->id;
                $lead->assigned_at ??= now();
                $lead->first_contact_at ??= now();
            }
            $lead->save();
            $this->audit->log('Lead', $lead->code, 'calificar', $before, "{$lead->score} / {$lead->status}", actor: $actor);

            return ['lead' => $lead, 'qualification' => $qualification->refresh(), 'reached_threshold' => $reached];
        });
    }

    /** Descartar con motivo (CRM-BR-14). */
    public function discard(Lead $lead, string $reasonCode, ?string $detail, User $actor): Lead
    {
        $this->access->ensureLeadManageable($lead, $actor);
        $this->ensureOpen($lead);
        $reason = $this->catalog->assertActive('discard_reason', $reasonCode, 'reason_code');

        return DB::transaction(function () use ($lead, $reason, $detail, $actor) {
            $before = $lead->status;
            $lead->forceFill([
                'status' => Lead::DISCARDED,
                'discard_reason_code' => $reason->code,
                'discard_detail' => $detail,
                'discarded_at' => now(),
                'discarded_at_step' => $lead->flowStep()[0],
            ])->save();
            $this->activities->autoCloseForLead($lead);
            $this->audit->log('Lead', $lead->code, 'descartar', $before, $reason->label.($detail ? " · {$detail}" : ''), actor: $actor);

            return $lead;
        });
    }

    /** Reabrir desde descartado/duplicado (§4.1). */
    public function reopen(Lead $lead, User $actor): Lead
    {
        $this->access->ensureLeadManageable($lead, $actor);
        if (! in_array($lead->status, [Lead::DISCARDED, Lead::DUPLICATE], true)) {
            throw ApiException::rule('CRM-BR-13', 'Solo se reabren leads descartados o duplicados');
        }

        return DB::transaction(function () use ($lead, $actor) {
            $before = $lead->status;
            $lead->forceFill([
                'status' => Lead::NEW, 'duplicate_of_type' => null, 'duplicate_of_id' => null,
                'discard_reason_code' => null, 'discard_detail' => null, 'discarded_at' => null, 'discarded_at_step' => null,
            ])->save();
            $this->audit->log('Lead', $lead->code, 'reabrir', $before, Lead::NEW, actor: $actor);

            return $lead;
        });
    }

    public function markDuplicate(Lead $lead, string $type, string $id, User $actor): Lead
    {
        $this->access->ensureLeadManageable($lead, $actor);
        $this->ensureOpen($lead);
        $match = collect($this->duplicates->find($lead->email, $lead->phone, $lead->id))
            ->first(fn ($m) => $m['type'] === $type && $m['id'] === $id)
            ?? throw ApiException::invalid('duplicate_of_id', 'El registro indicado no coincide con este lead');

        return DB::transaction(function () use ($lead, $match, $actor) {
            $before = $lead->status;
            $lead->forceFill(['status' => Lead::DUPLICATE, 'duplicate_of_type' => $match['type'], 'duplicate_of_id' => $match['id']])->save();
            $this->audit->log('Lead', $lead->code, 'marcar_duplicado', $before, "duplicado de {$match['code']}", actor: $actor);

            return $lead;
        });
    }

    /** "Fusionar en este lead": el origen pasa a duplicado del destino (§4.1). */
    public function merge(Lead $target, string $sourceId, User $actor): Lead
    {
        $this->access->ensureLeadManageable($target, $actor);
        $this->ensureOpen($target);
        $source = Lead::whereKey($sourceId)->first() ?? throw ApiException::invalid('source_lead_id', 'El lead a fusionar no existe');
        $this->access->ensureLeadVisible($source, $actor);
        if ($source->id === $target->id || in_array($source->status, [Lead::CONVERTED, Lead::DUPLICATE], true)) {
            throw ApiException::rule('CRM-BR-04', 'El lead origen no se puede fusionar (convertido, duplicado o el mismo lead)');
        }

        return DB::transaction(function () use ($target, $source, $actor) {
            foreach (self::MERGE_FILL as $field) {
                if (blank($target->{$field}) && filled($source->{$field})) {
                    $target->{$field} = $source->{$field};
                }
            }
            if ($target->pax === 0 && $source->pax > 0) {
                $target->pax = $source->pax;
            }
            $target->score = max($target->score, $source->score);
            $target->save();

            $before = $source->status;
            $source->forceFill(['status' => Lead::DUPLICATE, 'duplicate_of_type' => 'lead', 'duplicate_of_id' => $target->id])->save();

            Activity::where('related_type', 'lead')->where('related_id', $source->id)->update([
                'related_id' => $target->id, 'merged_from_lead_id' => $source->id,
                'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now(),
            ]);

            $this->audit->log('Lead', $source->code, 'fusionar', $before, "duplicado de {$target->code}", actor: $actor);
            $this->audit->log('Lead', $target->code, 'fusionar', $target->status, "absorbe {$source->code}", actor: $actor);

            return $target;
        });
    }

    /** Resolución `merge` en el alta: no crea fila; completa el lead existente y suma +5 al score. */
    private function mergeIncoming(array $data, array $matches, ?string $mergeInto, ?User $actor): Lead
    {
        $candidates = collect($matches)->where('type', 'lead')->whereIn('status', Lead::OPEN);
        $match = $mergeInto ? $candidates->firstWhere('id', $mergeInto) : $candidates->first();
        if ($match === null) {
            throw ApiException::rule('CRM-BR-04', 'Solo se puede fusionar con un lead coincidente en estado nuevo, contactado o calificado');
        }

        return DB::transaction(function () use ($data, $match, $actor) {
            $lead = Lead::whereKey($match['id'])->lockForUpdate()->firstOrFail();
            foreach ([...self::MERGE_FILL, 'pax'] as $field) {
                if (blank($lead->{$field}) || ($field === 'pax' && $lead->pax === 0)) {
                    if (filled($data[$field] ?? null)) {
                        $lead->{$field} = $data[$field];
                    }
                }
            }
            $stamp = $this->clock->now()->locale('es')->translatedFormat('d M');
            $origin = $this->catalog->label('lead_source', $data['source_code']).'/'.$this->catalog->label('lead_channel', $data['channel_code']);
            $lead->notes = trim(($lead->notes ? $lead->notes."\n" : '')."[Fusionado {$stamp} · {$origin}] ".($data['notes'] ?? ''));
            $lead->score = min(100, $lead->score + 5);
            $lead->save();

            $this->audit->log('Lead', $lead->code, 'fusionar', 'ingreso '.$this->catalog->label('lead_source', $data['source_code']), "fusionado en {$lead->code}", actor: $actor);

            return $lead;
        });
    }

    private function resolveOwnerForCreate(?string $ownerId, ?User $actor): ?string
    {
        if ($actor === null) {
            return null; // ingreso de sistema: cola comercial
        }
        if ($this->access->ownOnly($actor)) {
            return $actor->id; // CRM-T05: Ventas siempre queda como responsable
        }
        if ($ownerId === null || $ownerId === '') {
            return null;
        }

        return $this->users->assertAssignable($ownerId)->id;
    }

    private function assertCatalogs(array $data): void
    {
        foreach (['channel_code' => 'lead_channel', 'source_code' => 'lead_source', 'interest_code' => 'lead_interest'] as $field => $kind) {
            if (array_key_exists($field, $data)) {
                $this->catalog->assertActive($kind, $data[$field], $field);
            }
        }
    }

    private function ensureOpen(Lead $lead): void
    {
        if (! $lead->isOpen()) {
            throw ApiException::rule('CRM-BR-13', 'El lead está '.Lead::STATUS_LABELS[$lead->status].' y no admite esta acción');
        }
    }

    private function duplicateCode(Lead $lead): string
    {
        return $lead->duplicate_of_type === 'lead'
            ? (string) Lead::whereKey($lead->duplicate_of_id)->value('code')
            : (string) Client::whereKey($lead->duplicate_of_id)->value('code');
    }

    private function ref(Lead $lead): array
    {
        return ['lead_code' => $lead->code, 'related_type' => 'lead', 'related_id' => $lead->id];
    }

    /** Fecha tentativa ≥ hoy en la zona del tenant (CRM-BR-05). */
    public function isFutureOrToday(string $date): bool
    {
        return Carbon::parse($date, $this->clock->timezone())->startOfDay()->gte($this->clock->today());
    }
}
