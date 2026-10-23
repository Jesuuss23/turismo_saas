<?php

namespace App\Services\Crm;

use App\Exceptions\ApiException;
use App\Models\Crm\Client;
use App\Models\Crm\Opportunity;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Crm\Linked\LinkedData;
use App\Services\Notify;
use App\Services\SequenceService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Pipeline de oportunidades (spec 01 §4.2, PROC-006, CRM-BR-20..27). */
class OpportunityService
{
    public const EDITABLE = ['title', 'amount', 'currency', 'expected_close_date', 'probability', 'destination_label', 'pax', 'notes'];

    public const ORIGIN_LOYALTY = 'Fidelización';

    public function __construct(
        private readonly CrmAccess $access,
        private readonly CrmCatalog $catalog,
        private readonly CrmUsers $users,
        private readonly CrmClock $clock,
        private readonly LinkedData $linked,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /** Alta manual, por conversión o por recompra (CRM-BR-20/21). */
    public function create(array $data, User $actor, ?string $leadId = null, ?string $leadCode = null): Opportunity
    {
        $client = Client::whereKey($data['client_id'])->first() ?? throw ApiException::invalid('client_id', 'Seleccione un cliente válido');
        $this->access->ensureClient($client, $actor);
        $ownerId = $this->access->ownOnly($actor) ? $actor->id : $this->users->assertAssignable($data['owner_id'] ?? $actor->id)->id;
        $stage = $data['stage'] ?? 'calificacion';
        if (! in_array($stage, Opportunity::OPEN_STAGES, true)) {
            throw ApiException::invalid('stage', 'La etapa inicial debe ser una etapa abierta');
        }
        $origin = $data['origin'] ?? ($leadId ? 'Conversión de lead' : 'Manual');

        return DB::transaction(function () use ($data, $client, $ownerId, $stage, $origin, $leadId, $leadCode, $actor) {
            $opportunity = Opportunity::create(Arr::only($data, ['title', 'amount', 'currency', 'expected_close_date', 'destination_label', 'pax', 'notes', 'product_id']) + [
                'code' => $this->sequences->next('opportunity'),
                'client_id' => $client->id,
                'owner_id' => $ownerId,
                'stage' => $stage,
                'probability' => $this->catalog->probability($stage),
                'lead_id' => $leadId,
                'origin' => $origin,
                'pax' => (int) ($data['pax'] ?? 0),
            ]);

            $this->audit->log('Oportunidad', $opportunity->code, 'crear', $leadCode ? "desde {$leadCode}" : $origin,
                $this->catalog->stageName($stage).' · '.$opportunity->currency.' '.number_format((float) $opportunity->amount, 2), actor: $actor);
            if ($origin === self::ORIGIN_LOYALTY) {
                $this->audit->log('Cliente', $client->code, 'accion_fidelizacion', null, "Recompra {$opportunity->code}", actor: $actor);
            }
            if ($ownerId !== $actor->id) {
                $this->notify->push('oportunidad.asignada', 'portal', User::findOrFail($ownerId), "Oportunidad asignada: {$opportunity->title}", null, $this->ref($opportunity));
            }

            return $opportunity;
        });
    }

    /** Editar datos y probabilidad, no la etapa (§6.2). */
    public function update(Opportunity $opportunity, array $data, User $actor): Opportunity
    {
        $this->access->ensureOpportunity($opportunity, $actor);
        $before = $this->amountProbability($opportunity);
        $opportunity->fill(Arr::only($data, self::EDITABLE));
        $changed = array_keys($opportunity->getDirty());
        if ($changed === []) {
            return $opportunity;
        }

        return DB::transaction(function () use ($opportunity, $changed, $before, $actor) {
            $opportunity->save();
            $this->audit->log('Oportunidad', $opportunity->code, 'editar', $before, implode(', ', $changed).' → '.$this->amountProbability($opportunity), actor: $actor);

            return $opportunity;
        });
    }

    /**
     * Cambio de etapa manual (CRM-BR-22..26).
     *
     * @return array{opportunity: Opportunity, warnings: list<string>}
     */
    public function changeStage(Opportunity $opportunity, string $stage, User $actor, ?string $lostReason = null, ?string $lostDetail = null, bool $confirmReopen = false): array
    {
        $this->access->ensureOpportunity($opportunity, $actor);
        if ($stage === $opportunity->stage) {
            return ['opportunity' => $opportunity, 'warnings' => []];
        }
        if ($opportunity->isClosed() && ! in_array($stage, Opportunity::CLOSED, true) && ! $confirmReopen) {
            throw ApiException::rule('CRM-BR-26', 'Confirme la reapertura de la oportunidad cerrada');
        }
        $reason = null;
        if ($stage === Opportunity::LOST) {
            $reason = $this->catalog->assertActive('lost_reason', $lostReason, 'lost_reason_code');
            if ($reason->requires_detail && blank($lostDetail)) {
                throw ApiException::invalid('lost_detail', 'PROC-006: detalle obligatorio para el motivo "Otro"');
            }
        }

        $warnings = [];
        if ($stage === Opportunity::WON && ! $this->linked->hasApprovedQuote($opportunity->id)) {
            $warnings[] = 'CRM-BR-23: la oportunidad no tiene una cotización aprobada; recuerde que la reserva se genera desde la cotización aprobada.';
        }

        $from = $opportunity->stage;
        DB::transaction(function () use ($opportunity, $from, $stage, $reason, $lostDetail, $actor) {
            $this->applyStage($opportunity, $stage, $reason?->code, $lostDetail);
            $this->audit->log('Oportunidad', $opportunity->code, 'cambio_etapa', $this->catalog->stageName($from),
                $this->catalog->stageName($stage).($reason ? " · {$reason->label}".($lostDetail ? " ({$lostDetail})" : '') : ''), actor: $actor);

            if (in_array($stage, Opportunity::CLOSED, true)) {
                $label = $stage === Opportunity::WON ? 'Ganada' : 'Perdida';
                $this->notify->push("oportunidad.{$stage}", 'portal', $opportunity->owner, "{$opportunity->code} marcada como {$label}: {$opportunity->title}", null, $this->ref($opportunity));
            }
        });

        return ['opportunity' => $opportunity->refresh(), 'warnings' => $warnings];
    }

    public function reassign(Opportunity $opportunity, string $ownerId, ?string $note, User $actor): Opportunity
    {
        $this->access->ensureSupervisor($actor, 'reasignar oportunidades');
        $owner = $this->users->assertAssignable($ownerId);
        if ($owner->id === $opportunity->owner_id) {
            throw ApiException::invalid('owner_id', 'Seleccione un asesor distinto al actual');
        }

        return DB::transaction(function () use ($opportunity, $owner, $note, $actor) {
            $previous = $opportunity->owner?->name;
            $opportunity->forceFill(['owner_id' => $owner->id])->save();
            $this->audit->log('Oportunidad', $opportunity->code, 'reasignar', $previous, $owner->name.($note ? " · {$note}" : ''), actor: $actor);
            if ($owner->id !== $actor->id) {
                $this->notify->push('oportunidad.reasignado', 'portal', $owner, "Oportunidad {$opportunity->code} reasignada a su cartera", null, $this->ref($opportunity));
            }

            return $opportunity;
        });
    }

    /**
     * CRM-BR-27: sincronización automática desde Cotizaciones (actor Sistema).
     * created → cotizacion (si estaba en calificación/descubrimiento); negotiation → negociacion; converted → ganada.
     */
    public function syncFromQuote(string $opportunityId, string $quoteEvent): ?Opportunity
    {
        return DB::transaction(function () use ($opportunityId, $quoteEvent) {
            $opportunity = Opportunity::whereKey($opportunityId)->lockForUpdate()->first();
            if ($opportunity === null) {
                return null;
            }

            $target = match ($quoteEvent) {
                'created' => in_array($opportunity->stage, ['calificacion', 'descubrimiento'], true) ? 'cotizacion' : null,
                'negotiation' => in_array($opportunity->stage, [Opportunity::WON, Opportunity::LOST, 'negociacion'], true) ? null : 'negociacion',
                'converted' => $opportunity->stage === Opportunity::WON ? null : Opportunity::WON,
                default => null,
            };
            if ($target === null) {
                return $opportunity;
            }

            $from = $opportunity->stage;
            $this->applyStage($opportunity, $target);
            $this->audit->log('Oportunidad', $opportunity->code, 'cambio_etapa', $this->catalog->stageName($from), $this->catalog->stageName($target).' · automático desde Cotizaciones', system: true);

            return $opportunity;
        });
    }

    /** Destino por defecto al reabrir: la etapa en que se perdió si es abierta; si no, negociación. */
    public static function reopenStage(Opportunity $opportunity): string
    {
        return in_array($opportunity->lost_at_stage, Opportunity::OPEN_STAGES, true) ? $opportunity->lost_at_stage : 'negociacion';
    }

    private function applyStage(Opportunity $opportunity, string $stage, ?string $lostReason = null, ?string $lostDetail = null): void
    {
        $from = $opportunity->stage;
        $today = $this->clock->today()->toDateString();

        $opportunity->stage = $stage;
        $opportunity->probability = $this->catalog->probability($stage);

        if ($stage === Opportunity::WON) {
            $opportunity->forceFill(['closed_at' => $today, 'lost_reason_code' => null, 'lost_detail' => null]);
        } elseif ($stage === Opportunity::LOST) {
            $opportunity->forceFill(['closed_at' => $today, 'lost_reason_code' => $lostReason, 'lost_detail' => $lostDetail, 'lost_at_stage' => $from]);
        } else {
            $opportunity->forceFill(['closed_at' => null, 'lost_reason_code' => null, 'lost_detail' => null]);
        }

        $opportunity->save();
    }

    private function amountProbability(Opportunity $o): string
    {
        return $o->currency.' '.number_format((float) $o->amount, 2).' · '.$o->probability.'%';
    }

    private function ref(Opportunity $o): array
    {
        return ['opportunity_code' => $o->code, 'client_id' => $o->client_id, 'related_type' => 'opportunity', 'related_id' => $o->id];
    }
}
