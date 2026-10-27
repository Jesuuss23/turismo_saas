<?php

namespace App\Services\Collab;

use App\Exceptions\ApiException;
use App\Models\Bookings\Reservation;
use App\Models\Channel\B2bAgency;
use App\Models\Collab\ApprovalInfoRequest;
use App\Models\Collab\ApprovalRequest;
use App\Models\Collab\ApprovalStep;
use App\Models\Finance\Payment;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Finance\Fin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `ApprovalDecisionService` (§4.1, APR-BR-03/04/07/08/10): aprobar, rechazar, pedir/responder información, delegar,
 * retirar, reenviar y crear solicitudes genéricas o de reversión. Cada decisión bloquea la fila y valida If-Match (lock_version).
 */
class ApprovalDecisions
{
    public function __construct(
        private readonly ApprovalRouter $router,
        private readonly ApprovalRequests $requests,
        private readonly ApprovalEffects $effects,
        private readonly AuditLogger $audit,
    ) {}

    public function approve(ApprovalRequest $request, ?string $note, User $actor, Request $http): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $note, $actor, $http) {
            $r = $this->lock($request, $http);
            $rule = $this->router->assertCanDecide($actor, $r);
            $this->effects->precheckApprove($r, $actor);
            $note = trim((string) $note) ?: null;
            ApprovalStep::create(['request_id' => $r->id, 'level' => $r->current_level, 'rule_id' => $rule->id, 'decision' => 'aprobada',
                'decided_by' => $actor->id, 'note' => $note, 'decided_at' => now()]);
            $requester = $r->requester;

            if ($r->current_level < $r->total_levels) {
                $level = $r->current_level;
                $next = $this->router->levels($r->request_type, (float) $r->amount_base)[$level + 1] ?? $rule;
                $r->forceFill(['current_level' => $level + 1, 'current_rule_id' => $next->id, 'delegated_to' => null, 'level_started_at' => now(),
                    'due_at' => now()->addHours($next->sla_hours), 'sla_warned_at' => null, 'sla_overdue_at' => null])->save();
                $this->requests->event($r, 'aprobada_nivel', $actor, $note, ['level' => $level]);
                $this->audit->log('Aprobación', $r->code, "aprobar_nivel_{$level}", "Nivel {$level} pendiente", 'Nivel '.($level + 1).' pendiente'.($note ? " · {$note}" : ''), actor: $actor);
                $this->requests->notifyApprovers($r, 'aprobacion.solicitada', "Aprobación pendiente {$r->code} (nivel ".($level + 1)."): {$r->title}");
                $this->requests->notifyUser($requester, $r, 'aprobacion.resultado', "{$r->code}: nivel {$level} aprobado", 'Pasa al nivel '.($level + 1));

                return $r->fresh();
            }

            $this->effects->approve($r, $actor, $note);
            $r->forceFill(['status' => 'aprobada', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note, 'delegated_to' => null])->save();
            $this->requests->event($r, 'aprobada', $actor, $note);
            $this->audit->log('Aprobación', $r->code, 'aprobar', 'Pendiente', 'Aprobada · '.CollabCatalog::EFFECTS[$r->request_type].($note ? " · {$note}" : ''), actor: $actor);
            $this->requests->notifyUser($requester, $r, 'aprobacion.resultado', "{$r->code} aprobada: {$r->title}", $note);

            return $r->fresh();
        });
    }

    public function reject(ApprovalRequest $request, ?string $note, User $actor, Request $http): ApprovalRequest
    {
        $note = trim((string) $note);
        if ($note === '') {
            throw ApiException::invalid('note', 'Indique el motivo del rechazo (APR-BR-07).');
        }

        return DB::transaction(function () use ($request, $note, $actor, $http) {
            $r = $this->lock($request, $http);
            $rule = $this->router->assertCanDecide($actor, $r);
            ApprovalStep::create(['request_id' => $r->id, 'level' => $r->current_level, 'rule_id' => $rule->id, 'decision' => 'rechazada',
                'decided_by' => $actor->id, 'note' => $note, 'decided_at' => now()]);
            $this->effects->reject($r, $actor, $note);
            $r->forceFill(['status' => 'rechazada', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note, 'delegated_to' => null])->save();
            $this->requests->event($r, 'rechazada', $actor, $note, ['level' => $r->current_level]);
            $this->audit->log('Aprobación', $r->code, 'rechazar', 'Pendiente', "Rechazada en nivel {$r->current_level} · {$note}", actor: $actor);
            $this->requests->notifyUser($r->requester, $r, 'aprobacion.resultado', "{$r->code} rechazada: {$r->title}", $note);

            return $r->fresh();
        });
    }

    /** APR-BR-10: pausa el SLA. */
    public function askInfo(ApprovalRequest $request, ?string $question, User $actor, Request $http): ApprovalRequest
    {
        $question = trim((string) $question);
        if ($question === '') {
            throw ApiException::invalid('question', 'Escriba la consulta para el solicitante.');
        }

        return DB::transaction(function () use ($request, $question, $actor, $http) {
            $r = $this->lock($request, $http);
            $this->router->assertCanDecide($actor, $r);
            ApprovalInfoRequest::create(['request_id' => $r->id, 'asked_by' => $actor->id, 'question' => $question, 'asked_at' => now()]);
            $r->forceFill(['status' => 'info', 'paused_at' => now()])->save();
            $this->requests->event($r, 'info_solicitada', $actor, $question);
            $this->audit->log('Aprobación', $r->code, 'pedir_informacion', 'Pendiente', "Información solicitada · {$question}", actor: $actor);
            $this->requests->notifyUser($r->requester, $r, 'aprobacion.informacion', "{$r->code}: {$actor->name} pide información", $question);

            return $r->fresh();
        });
    }

    /** Responde el solicitante, o Manager/Admin; si el solicitante es proveedor o sistema, también quien puede decidir el nivel (§12-5). */
    public function answer(ApprovalRequest $request, string $infoId, ?string $answer, User $actor): ApprovalRequest
    {
        $answer = trim((string) $answer);
        if ($answer === '') {
            throw ApiException::invalid('answer', 'Escriba la respuesta.');
        }

        return DB::transaction(function () use ($request, $infoId, $answer, $actor) {
            $r = ApprovalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $info = ApprovalInfoRequest::where('request_id', $r->id)->whereKey($infoId)->whereNull('answered_at')->first()
                ?? throw ApiException::rule('APR-BR-10', 'La consulta no existe o ya fue respondida');
            if ($r->status !== 'info') {
                throw ApiException::rule('APR-BR-10', 'La solicitud no espera información');
            }
            if (! $this->canAnswer($actor, $r)) {
                throw ApiException::forbidden('APR-BR-10', 'Solo el solicitante (o Manager/Admin) responde la consulta', ['approvals.view']);
            }
            $paused = $r->paused_at ? (int) $r->paused_at->diffInSeconds(now(), true) : 0;
            $info->forceFill(['answer' => $answer, 'answered_by' => $actor->id, 'answered_at' => now()])->save();
            $r->forceFill(['status' => 'pendiente', 'paused_at' => null, 'due_at' => $r->due_at?->copy()->addSeconds($paused)])->save();
            $this->requests->event($r, 'info_respondida', $actor, $answer, ['paused_seconds' => $paused]);
            $this->audit->log('Aprobación', $r->code, 'responder_informacion', 'Información solicitada', "Pendiente · {$answer}", actor: $actor);
            $this->requests->notifyUser(User::find($info->asked_by), $r, 'aprobacion.informacion', "{$r->code}: consulta respondida", $answer);

            return $r->fresh();
        });
    }

    public function canAnswer(User $actor, ApprovalRequest $r): bool
    {
        return $r->requester_id === $actor->id || $this->router->seesAll($actor)
            || ($r->requester_kind !== 'user' && $actor->checkPermissionTo($this->router->currentRule($r)->permission));
    }

    public function delegate(ApprovalRequest $request, ?string $userId, User $actor, Request $http): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $userId, $actor, $http) {
            $r = $this->lock($request, $http);
            $rule = $this->router->assertCanDecide($actor, $r);
            $target = $this->router->eligibleUsers($r, $rule, true)->first(fn (User $u) => $u->id === $userId && $u->id !== $actor->id)
                ?? throw ApiException::invalid('user_id', "Elija un usuario activo con el permiso {$rule->permission} que no sea el solicitante ni usted (APR-BR-04).");
            $r->forceFill(['delegated_to' => $target->id])->save();
            $this->requests->event($r, 'delegada', $actor, null, ['to' => $target->name]);
            $this->audit->log('Aprobación', $r->code, 'delegar', null, "Delegada a {$target->name}", actor: $actor);
            $this->requests->notifyUser($target, $r, 'aprobacion.delegada', "{$actor->name} le delegó {$r->code}: {$r->title}");

            return $r->fresh();
        });
    }

    public function withdraw(ApprovalRequest $request, User $actor, Request $http): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $actor, $http) {
            $r = $this->lock($request, $http);
            if ($r->requester_id !== $actor->id) {
                throw ApiException::forbidden('APR-WITHDRAW', 'Solo el solicitante puede retirar la solicitud', ['approvals.view']);
            }
            if (! $r->isLive()) {
                throw ApiException::rule('APR-BR-08', 'La solicitud ya fue resuelta');
            }
            if (! in_array($r->request_type, CollabCatalog::WITHDRAWABLE, true)) {
                throw ApiException::rule('APR-WITHDRAW', 'Este tipo de solicitud no se retira desde la bandeja');
            }
            $this->effects->withdraw($r, $actor);
            $r->forceFill(['status' => 'anulada', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => 'Retirada por el solicitante', 'paused_at' => null])->save();
            $this->requests->event($r, 'retirada', $actor);
            $this->audit->log('Aprobación', $r->code, 'retirar', CollabCatalog::STATUS_LABELS[$request->status], 'Anulada (retirada)', actor: $actor);

            return $r->fresh();
        });
    }

    /** CxP/comisión observada → nueva solicitud en la ronda siguiente. */
    public function resubmit(ApprovalRequest $request, ?string $note, User $actor): ApprovalRequest
    {
        $permission = CollabCatalog::RESUBMITTABLE[$request->request_type] ?? throw ApiException::rule('APR-RESUBMIT', 'Solo se reenvían cuentas por pagar y comisiones observadas');
        if ($request->requester_id !== $actor->id && ! $actor->checkPermissionTo($permission)) {
            throw ApiException::forbidden('APR-RESUBMIT', "Reenvía el solicitante o un usuario con {$permission}", [$permission]);
        }

        return DB::transaction(function () use ($request, $note, $actor) {
            $r = ApprovalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'rechazada') {
                throw ApiException::rule('APR-RESUBMIT', 'Solo se reenvía una solicitud rechazada (observada)');
            }
            $round = $this->effects->resubmit($r, $actor);
            $this->requests->event($r, 'reenviada', $actor, $note, ['round' => $round]);
            $new = $this->requests->open([
                'type' => $r->request_type, 'subject_type' => $r->subject_type, 'subject_id' => $r->subject_id, 'subject_code' => $r->subject_code,
                'dedupe_key' => "{$r->request_type}:{$r->subject_id}:{$round}", 'round' => $round, 'title' => preg_replace('/ · ronda \d+$/', '', $r->title)." · ronda {$round}",
                'justification' => trim((string) $note) ?: $r->justification, 'requester' => $r->requester, 'requester_kind' => $r->requester_kind, 'amount' => $r->amount, 'currency' => $r->currency,
                'fx_rate' => $r->fx_rate, 'snapshot' => $r->origin_snapshot,
            ], $actor);
            $this->audit->log('Aprobación', $r->code, 'reenviar', 'Rechazada', "Reenviada como {$new->code} (ronda {$round})", actor: $actor);

            return $new;
        });
    }

    /** Nueva solicitud genérica (§2 «Nueva solicitud»): crédito B2B, reembolso u otra excepción. */
    public function createGeneric(array $data, User $actor): ApprovalRequest
    {
        $type = (string) ($data['type'] ?? '');
        if (! in_array($type, CollabCatalog::GENERIC, true)) {
            throw ApiException::invalid('type', 'Tipo de solicitud genérica no válido.');
        }
        $title = trim((string) ($data['title'] ?? ''));
        $justification = trim((string) ($data['justification'] ?? ''));
        if ($title === '') {
            throw ApiException::invalid('title', 'Indique el asunto de la solicitud.');
        }
        if ($justification === '') {
            throw ApiException::invalid('justification', 'Explique el motivo de la solicitud.');
        }
        $amount = (float) ($data['amount'] ?? 0);
        $currency = in_array($data['currency'] ?? 'PEN', ['PEN', 'USD'], true) ? ($data['currency'] ?? 'PEN') : throw ApiException::invalid('currency', 'Moneda no válida.');
        if ($type !== 'other' && $amount <= 0) {
            throw ApiException::invalid('amount', 'El monto debe ser mayor a 0.');
        }
        $subject = ['subject_type' => null, 'subject_id' => null, 'subject_code' => null];
        $snapshot = [];
        if ($type === 'credit_b2b') {
            $agency = B2bAgency::where('code', $data['agency_code'] ?? '')->first() ?? throw ApiException::invalid('agency_code', 'Seleccione la agencia B2B.');
            if ($agency->credit_currency !== $currency) {
                throw ApiException::invalid('currency', "La línea de crédito de {$agency->name} está en {$agency->credit_currency}.");
            }
            $subject = ['subject_type' => 'channel_b2b_agencies', 'subject_id' => $agency->id, 'subject_code' => $agency->code];
            $snapshot = ['agency' => $agency->name, 'credit_limit' => (string) $agency->credit_limit, 'credit_used' => (string) $agency->credit_used,
                'status' => $agency->effectiveStatus(), 'valid_until' => $data['valid_until'] ?? null];
        } elseif ($type === 'refund') {
            $reservation = Reservation::where('code', $data['reservation_code'] ?? '')->first() ?? throw ApiException::invalid('reservation_code', 'Indique la reserva del reembolso.');
            $subject = ['subject_type' => 'booking_reservations', 'subject_id' => $reservation->id, 'subject_code' => $reservation->code];
            $snapshot = ['reservation' => $reservation->code, 'status' => $reservation->status, 'total' => (string) $reservation->total_amount, 'currency' => $reservation->currency];
        }

        return DB::transaction(fn () => $this->requests->open($subject + [
            'type' => $type, 'is_generic' => true, 'dedupe_key' => "{$type}:".str()->uuid(), 'title' => $title, 'justification' => $justification,
            'requester' => $actor, 'amount' => $amount, 'currency' => $currency, 'fx_rate' => $data['fx_rate'] ?? null, 'snapshot' => $snapshot ?: null,
        ], $actor));
    }

    /** PROC-043: solicitud de reversión desde Finanzas (APR-BR-09: solo pagos confirmados o aplicados). */
    public function requestReversal(Payment $payment, ?string $reason, User $actor): ApprovalRequest
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw ApiException::invalid('reason', 'Indique el motivo de la reversión.');
        }

        return DB::transaction(function () use ($payment, $reason, $actor) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (! in_array($payment->status, ['confirmado', 'aplicado'], true)) {
                throw ApiException::rule('APR-BR-09', 'Solo se solicita la reversión de pagos confirmados o aplicados');
            }
            if ($payment->reversal_request_id && ApprovalRequest::whereKey($payment->reversal_request_id)->whereIn('status', ApprovalRequest::LIVE)->exists()) {
                throw ApiException::rule('APR-BR-11', 'El pago ya tiene una solicitud de reversión en curso');
            }
            $request = $this->requests->open([
                'type' => 'reversal', 'subject_type' => 'fin_payments', 'subject_id' => $payment->id, 'subject_code' => $payment->code,
                'dedupe_key' => "reversal:{$payment->id}", 'title' => "Reversión del pago {$payment->code}", 'justification' => $reason, 'requester' => $actor,
                'amount' => $payment->amount, 'currency' => $payment->currency, 'fx_rate' => $payment->fx_rate,
                'snapshot' => ['status' => $payment->status, 'method' => $payment->method, 'receivable' => $payment->receivable?->code, 'reservation' => $payment->reservation?->code],
            ], $actor);
            $payment->forceFill(['reversal_request_id' => $request->id])->save();
            Fin::history('payment', $payment->id, $payment->status, "Reversión solicitada ({$request->code}) · {$reason}", $actor);
            $this->audit->log('Pago', $payment->code, 'solicitar_reversion', null, "{$request->code} · {$reason}", actor: $actor);

            return $request;
        });
    }

    /** SEC-BR-17 + APR-BR-08: fila bloqueada y versión del cliente igual a la actual (dos aprobadores → uno recibe 409). */
    private function lock(ApprovalRequest $request, Request $http): ApprovalRequest
    {
        $r = ApprovalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
        $r->assertIfMatch($http);

        return $r;
    }
}
