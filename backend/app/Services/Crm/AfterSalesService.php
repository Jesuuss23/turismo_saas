<?php

namespace App\Services\Crm;

use App\Exceptions\ApiException;
use App\Models\Crm\Activity;
use App\Models\Crm\AfterSalesCase;
use App\Models\Crm\CaseEvent;
use App\Models\Crm\Client;
use App\Models\Crm\Survey;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Crm\Linked\LinkedData;
use App\Services\Notify;
use App\Services\SequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Postventa: encuestas NPS y casos (spec 01 §4.4, PROC-009, CRM-BR-37..44). */
class AfterSalesService
{
    public const PRIORITIES = ['baja', 'media', 'alta', 'critica'];

    public const STATUS_LABELS = ['abierta' => 'Abierta', 'en_proceso' => 'En proceso', 'resuelta' => 'Resuelta', 'cerrada' => 'Cerrada'];

    public function __construct(
        private readonly CrmAccess $access,
        private readonly CrmCatalog $catalog,
        private readonly CrmUsers $users,
        private readonly CrmClock $clock,
        private readonly LinkedData $linked,
        private readonly ActivityService $activities,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /**
     * Encuesta NPS (CRM-BR-38..41). Un detractor (≤ 6) genera caso + actividad + Email en la misma transacción.
     *
     * @return array{survey: Survey, case: ?AfterSalesCase, activity: ?Activity, warnings: list<string>}
     */
    public function recordSurvey(array $data, User $actor): array
    {
        $client = Client::whereKey($data['client_id'])->first() ?? throw ApiException::invalid('client_id', 'Seleccione un cliente válido');
        $this->access->ensureClient($client, $actor);
        $channel = $this->catalog->assertActive('survey_channel', $data['channel_code'] ?? null, 'channel_code');

        $warnings = [];
        $reservation = null;
        if (! empty($data['reservation_id'])) {
            $reservation = $this->linked->reservation($data['reservation_id']);
            if ($reservation === null || $reservation['client_id'] !== $client->id || $reservation['status'] === 'cancelada'
                || ($this->access->ownOnly($actor) && ($reservation['owner_id'] ?? null) !== $actor->id && $client->owner_id !== $actor->id)) {
                throw ApiException::invalid('reservation_id', 'La reserva no existe, está cancelada o no está en su cartera');
            }
            if ($reservation['status'] === 'en_operacion') {
                $warnings[] = 'El viaje aún está en operación.';
            }
            if (Survey::where('reservation_id', $reservation['id'])->exists()) {
                throw ApiException::rule('CRM-BR-41', "La reserva {$reservation['code']} ya tiene una encuesta registrada");
            }
        }

        $score = (int) $data['score'];

        return DB::transaction(function () use ($data, $client, $channel, $reservation, $score, $warnings, $actor) {
            $survey = Survey::create([
                'code' => $this->sequences->next('survey'),
                'client_id' => $client->id,
                'reservation_id' => $reservation['id'] ?? null,
                'score' => $score,
                'comment' => $data['comment'] ?? null,
                'channel_code' => $channel->code,
                'responded_at' => now(),
                'recorded_by' => $actor->id,
            ]);
            $this->audit->log('Encuesta', $survey->code, 'crear', null, "NPS {$score} · {$client->code}", actor: $actor);

            $previousNps = $client->nps_last;
            $client->forceFill(['nps_last' => $score])->save();
            $this->audit->log('Cliente', $client->code, 'actualizar_nps', $previousNps === null ? null : (string) $previousNps, (string) $score, actor: $actor);

            [$case, $activity] = $score <= 6 ? $this->handleDetractor($survey, $client, $reservation, $actor) : [null, null];

            return ['survey' => $survey->refresh(), 'case' => $case, 'activity' => $activity, 'warnings' => $warnings];
        });
    }

    /** CRM-BR-39. */
    private function handleDetractor(Survey $survey, Client $client, ?array $reservation, User $actor): array
    {
        $reservationLabel = $reservation['code'] ?? 'sin reserva';
        $case = $this->openCase([
            'client_id' => $client->id,
            'reservation_id' => $reservation['id'] ?? null,
            'survey_id' => $survey->id,
            'origin' => 'nps_auto',
            'type_code' => 'reclamo',
            'priority' => $survey->score <= 3 ? 'alta' : 'media',
            'subject' => "Detractor NPS {$survey->score} — {$reservationLabel}",
            'description' => $survey->comment ?: "Encuesta {$survey->code} con puntaje {$survey->score}",
            'owner_id' => $client->owner_id,
        ], $actor, "encuesta {$survey->code}");

        $contact = $client->primaryContact?->name ?? $client->name;
        $activity = $this->activities->schedule('postventa', "Resolver {$case->code} — contactar a {$contact}", $client->owner_id,
            $this->clock->tomorrowAt(10), 'client', $client->id, $actor);

        $owner = $client->owner;
        $this->notify->push('postventa.detractor', 'email', $owner, "Detractor NPS {$survey->score}: {$client->name} — caso {$case->code}",
            "El cliente {$client->name} respondió la encuesta {$survey->code} con {$survey->score}. Se abrió el caso {$case->code}.",
            ['client_id' => $client->id, 'related_type' => 'case', 'related_id' => $case->id]);

        return [$case, $activity];
    }

    /** Registro manual (CRM-BR-43). Felicitación nace cerrada. */
    public function createCase(array $data, User $actor): AfterSalesCase
    {
        $client = Client::whereKey($data['client_id'])->first() ?? throw ApiException::invalid('client_id', 'Seleccione un cliente válido');
        $this->access->ensureClient($client, $actor);
        $this->catalog->assertActive('case_type', $data['type_code'] ?? null, 'type_code');
        $ownerId = $this->access->ownOnly($actor) ? $actor->id : $this->users->assertAssignable($data['owner_id'] ?? $actor->id)->id;

        return DB::transaction(function () use ($data, $client, $ownerId, $actor) {
            $case = $this->openCase([
                'client_id' => $client->id,
                'reservation_id' => $data['reservation_id'] ?? null,
                'origin' => 'manual',
                'type_code' => $data['type_code'],
                'priority' => $data['priority'] ?? 'media',
                'subject' => $data['subject'],
                'description' => $data['description'],
                'owner_id' => $ownerId,
            ], $actor, $this->catalog->label('case_type', $data['type_code']));

            if ($ownerId !== $actor->id) {
                $this->notify->push('postventa.caso', 'portal', User::findOrFail($ownerId), "Caso {$case->code} asignado: {$case->subject}", null, [
                    'client_id' => $client->id, 'related_type' => 'case', 'related_id' => $case->id,
                ]);
            }

            return $case;
        });
    }

    public function start(AfterSalesCase $case, User $actor): AfterSalesCase
    {
        $this->ensureManageable($case, $actor);
        $this->ensureNotBook($case);
        if ($case->status !== AfterSalesCase::OPEN) {
            throw ApiException::rule('CRM-BR-43', 'Solo se inicia la atención de un caso abierto');
        }

        return $this->transition($case, AfterSalesCase::IN_PROGRESS, 'Atención iniciada', $actor);
    }

    public function followUp(AfterSalesCase $case, string $message, User $actor): CaseEvent
    {
        $this->ensureManageable($case, $actor);
        if (! $case->isActive()) {
            throw ApiException::rule('CRM-BR-43', 'Solo se registran seguimientos en casos abiertos o en proceso');
        }

        return DB::transaction(function () use ($case, $message, $actor) {
            $event = $this->event($case, 'seguimiento', null, null, $message, $actor);
            $case->touch();
            $this->audit->log('Reclamo', $case->code, 'seguimiento', self::STATUS_LABELS[$case->status], Str::limit($message, 60), actor: $actor);

            return $event;
        });
    }

    public function resolve(AfterSalesCase $case, string $resolution, User $actor): AfterSalesCase
    {
        $this->ensureManageable($case, $actor);
        $this->ensureNotBook($case);
        if (! $case->isActive()) {
            throw ApiException::rule('CRM-BR-43', 'Solo se resuelve un caso abierto o en proceso');
        }
        $case->forceFill(['resolution' => $resolution, 'resolved_at' => now()]);

        return $this->transition($case, AfterSalesCase::RESOLVED, $resolution, $actor);
    }

    public function close(AfterSalesCase $case, User $actor): AfterSalesCase
    {
        $this->ensureManageable($case, $actor);
        $this->ensureNotBook($case);
        if ($case->status !== AfterSalesCase::RESOLVED) {
            throw ApiException::rule('CRM-BR-43', 'Solo se cierra un caso resuelto');
        }
        $case->forceFill(['closed_at' => now()]);
        $case = $this->transition($case, AfterSalesCase::CLOSED, 'Caso cerrado', $actor);

        if ($email = $case->client->email) {
            $this->notify->push('postventa.cierre', 'email', $email, "Su caso {$case->code} ha sido cerrado", $case->resolution, [
                'client_id' => $case->client_id, 'related_type' => 'case', 'related_id' => $case->id,
            ]);
        }

        return $case;
    }

    /** Spec 12 §3.10: las hojas del libro siguen su propio flujo (evaluar, responder, cerrar) en Libro de reclamaciones. */
    private function ensureNotBook(AfterSalesCase $case): void
    {
        if ($case->is_book) {
            throw ApiException::rule('LR-BR-07', "La hoja {$case->book_number} se gestiona en Libro de reclamaciones.");
        }
    }

    /** CRM-BR-44. */
    public function ensureManageable(AfterSalesCase $case, User $actor): void
    {
        if (! $actor->hasPermissionTo('crm.clients.manage')) {
            throw ApiException::forbidden('CRM-BR-44', 'Su rol no puede gestionar casos de postventa', ['crm.clients.manage']);
        }
        $this->access->ensureCase($case, $actor);
    }

    private function openCase(array $attributes, User $actor, string $origin): AfterSalesCase
    {
        $closedAtBirth = $attributes['type_code'] === 'felicitacion';
        $case = AfterSalesCase::create($attributes + [
            'code' => $this->sequences->next('case'),
            'status' => $closedAtBirth ? AfterSalesCase::CLOSED : AfterSalesCase::OPEN,
            'closed_at' => $closedAtBirth ? now() : null,
        ]);
        $this->event($case, 'registro', null, $case->status, $case->description, $actor);
        $this->audit->log('Reclamo', $case->code, 'crear', $origin, self::STATUS_LABELS[$case->status].' · '.$case->priority, actor: $actor);

        return $case;
    }

    private function transition(AfterSalesCase $case, string $to, string $message, User $actor): AfterSalesCase
    {
        return DB::transaction(function () use ($case, $to, $message, $actor) {
            $from = $case->status;
            $case->status = $to;
            $case->save();
            $this->event($case, 'estado', $from, $to, $message, $actor);
            $this->audit->log('Reclamo', $case->code, 'cambio_estado', self::STATUS_LABELS[$from], self::STATUS_LABELS[$to].' · '.Str::limit($message, 60), actor: $actor);

            return $case;
        });
    }

    private function event(AfterSalesCase $case, string $event, ?string $from, ?string $to, string $message, ?User $actor): CaseEvent
    {
        return CaseEvent::create([
            'case_id' => $case->id, 'event' => $event, 'from_status' => $from, 'to_status' => $to,
            'message' => $message, 'user_id' => $actor?->id, 'created_at' => now(),
        ]);
    }
}
