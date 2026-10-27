<?php

namespace App\Services\Collab;

use App\Models\Collab\ApprovalEvent;
use App\Models\Collab\ApprovalRequest;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\CorrelationId;
use App\Services\Finance\FxRates;
use App\Services\Notify;
use App\Services\SequenceService;

/**
 * `ApprovalRequestFactory` de la spec (§8): abre solicitudes desde los módulos de origen o la bandeja, registra la
 * trazabilidad (§3.5) y cierra las que se resolvieron en el origen (APR-BR-12).
 */
class ApprovalRequests
{
    public function __construct(
        private readonly ApprovalRouter $router,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
        private readonly FxRates $fx,
        private readonly CorrelationId $correlation,
    ) {}

    /**
     * Abre una solicitud (o devuelve la viva con el mismo `dedupe_key`, APR-BR-11).
     *
     * @param  array{type: string, dedupe_key: string, title: string, amount?: float|string, currency?: string, fx_rate?: mixed, subject_type?: ?string,
     *     subject_id?: ?string, subject_code?: ?string, subject_version?: ?int, justification?: ?string, requester?: ?User, requester_kind?: string,
     *     requester_supplier_id?: ?string, snapshot?: ?array, is_generic?: bool, round?: int}  $data
     */
    public function open(array $data, ?User $actor = null): ApprovalRequest
    {
        if ($live = ApprovalRequest::where('dedupe_key', $data['dedupe_key'])->whereIn('status', ApprovalRequest::LIVE)->first()) {
            return $live;
        }
        $currency = $data['currency'] ?? 'PEN';
        $fx = $this->fx->rateOf($currency, $data['fx_rate'] ?? null);
        $amount = round((float) ($data['amount'] ?? 0), 2);
        $levels = $this->router->levels($data['type'], round($amount * (float) $fx, 2));
        $rule = $levels[1];
        $requester = $data['requester'] ?? null;

        $request = ApprovalRequest::create([
            'code' => $this->sequences->next('approval_request'), 'request_type' => $data['type'], 'is_generic' => $data['is_generic'] ?? false,
            'subject_type' => $data['subject_type'] ?? null, 'subject_id' => $data['subject_id'] ?? null, 'subject_code' => $data['subject_code'] ?? null,
            'subject_version' => $data['subject_version'] ?? null, 'dedupe_key' => $data['dedupe_key'], 'round' => $data['round'] ?? 1,
            'title' => mb_substr($data['title'], 0, 200), 'justification' => $data['justification'] ?? null,
            'requester_id' => $requester?->id, 'requester_kind' => $data['requester_kind'] ?? ($requester ? 'user' : 'system'),
            'requester_supplier_id' => $data['requester_supplier_id'] ?? null,
            'amount' => number_format($amount, 2, '.', ''), 'currency' => $currency, 'fx_rate' => $fx, 'status' => 'pendiente',
            'current_level' => 1, 'total_levels' => count($levels), 'current_rule_id' => $rule->id, 'level_started_at' => now(),
            'due_at' => now()->addHours($rule->sla_hours), 'origin_snapshot' => $data['snapshot'] ?? null,
        ]);
        $this->event($request, 'creada', $actor ?? $requester, $request->justification, ['levels' => count($levels), 'rule' => $rule->code]);
        $this->audit->log('Aprobación', $request->code, 'crear', null,
            CollabCatalog::TYPES[$request->request_type]." · {$request->title} · {$request->currency} {$request->amount} · {$request->total_levels} nivel(es)",
            actor: $actor ?? $requester, system: ($actor ?? $requester) === null);
        $this->notifyApprovers($request, 'aprobacion.solicitada', "Aprobación pendiente {$request->code}: {$request->title}");

        return $request;
    }

    /** APR-BR-12: el registro se resolvió en su módulo; la bandeja cierra la solicitud viva como `closed_in_origin`. */
    public function closeFromOrigin(string $subjectType, string $subjectId, string $status, ?User $actor, ?string $note = null, ?string $type = null): int
    {
        $closed = 0;
        $query = ApprovalRequest::where('subject_type', $subjectType)->where('subject_id', $subjectId)->whereIn('status', ApprovalRequest::LIVE)
            ->when($type, fn ($q, $t) => $q->where('request_type', $t));
        foreach ($query->lockForUpdate()->get() as $request) {
            $before = CollabCatalog::STATUS_LABELS[$request->status];
            $request->forceFill([
                'status' => $status, 'closed_in_origin' => true, 'decided_by' => $actor?->id, 'decided_at' => now(), 'paused_at' => null,
                'decision_note' => $note ?? ($status === 'rechazada' ? 'Rechazada en el módulo de origen' : 'Resuelta en el módulo de origen'),
            ])->save();
            $this->event($request, 'cerrada_en_origen', $actor, $request->decision_note, ['status' => $status]);
            $this->audit->log('Aprobación', $request->code, 'cerrar_en_origen', $before, CollabCatalog::STATUS_LABELS[$status].' en el módulo de origen', actor: $actor, system: $actor === null);
            $closed++;
        }

        return $closed;
    }

    public function event(ApprovalRequest $request, string $event, ?User $actor, ?string $note = null, ?array $payload = null): void
    {
        ApprovalEvent::create(['request_id' => $request->id, 'event' => $event, 'actor_id' => $actor?->id, 'note' => $note, 'payload' => $payload,
            'correlation_id' => $this->correlation->get(), 'occurred_at' => now()]);
    }

    public function notifyApprovers(ApprovalRequest $request, string $event, string $subject): void
    {
        foreach ($this->router->eligibleUsers($request) as $user) {
            $this->notify->push($event, 'portal', $user, $subject, "Nivel {$request->current_level} de {$request->total_levels} · SLA ".$request->due_at?->format('d/m H:i'),
                ['related_type' => 'approval', 'related_id' => $request->id]);
        }
    }

    public function notifyUser(?User $user, ApprovalRequest $request, string $event, string $subject, ?string $body = null): void
    {
        if ($user) {
            $this->notify->push($event, 'portal', $user, $subject, $body, ['related_type' => 'approval', 'related_id' => $request->id]);
        }
    }
}
