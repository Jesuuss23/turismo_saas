<?php

namespace App\Services\Channels;

use App\Exceptions\ApiException;
use App\Models\Bookings\Reservation;
use App\Models\Channel\SupportTicket;
use App\Models\Channel\SupportTicketEvent;
use App\Models\Crm\Client;
use App\Models\Iam\User;
use App\Models\Ops\Order;
use App\Services\AuditLogger;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Notify;
use App\Services\SequenceService;
use Illuminate\Support\Facades\DB;

/** Solicitudes de los portales (spec 08 §3.12, PCL-BR-09, PPX-BR-06): asignadas al asesor del cliente o al coordinador de la operación. */
class SupportTickets
{
    /** "Respuesta en un máximo de 4 horas hábiles" (texto del portal). */
    public const SLA_HOURS = 4;

    public function __construct(
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    public function forClient(Client $client, array $data, User $actor, ?Reservation $reservation = null): SupportTicket
    {
        $subject = trim((string) ($data['subject'] ?? ''));
        $message = trim((string) ($data['message'] ?? ''));
        if ($subject === '') {
            throw ApiException::invalid('subject', 'Indique el asunto.');
        }
        if ($message === '') {
            throw ApiException::invalid('message', 'Escriba su mensaje.');
        }
        $category = in_array($data['category'] ?? null, SupportTicket::CLIENT_CATEGORIES, true) ? $data['category'] : 'Consulta general';
        $owner = $client->owner ?? CatalogRecipients::sales()->first() ?? $actor;

        return $this->create(['client_id' => $client->id, 'reservation_id' => $reservation?->id, 'source' => 'portal_cliente', 'subject' => mb_substr($subject, 0, 200),
            'category' => $category, 'priority' => in_array($category, ['Reclamo', 'Pagos'], true) ? 'alta' : 'media', 'message' => mb_substr($message, 0, 5000), 'owner_id' => $owner->id], $actor, $owner);
    }

    /** PPX-BR-06: mensaje obligatorio; Salud → prioridad alta; al coordinador de la operación. */
    public function forPassenger(Reservation $reservation, array $data, User $actor): SupportTicket
    {
        $message = trim((string) ($data['message'] ?? ''));
        if ($message === '') {
            throw ApiException::invalid('message', 'Escriba su mensaje para operaciones.');
        }
        $topic = in_array($data['topic'] ?? null, SupportTicket::PAX_TOPICS, true) ? $data['topic'] : 'Otro';
        $owner = Order::where('reservation_id', $reservation->id)->first()?->coordinator ?? CatalogRecipients::role('operations')->first() ?? $reservation->owner;

        return $this->create(['client_id' => $reservation->client_id, 'reservation_id' => $reservation->id, 'source' => 'portal_pasajero',
            'subject' => "{$topic} · {$reservation->code}", 'category' => 'Operaciones', 'topic' => $topic, 'priority' => $topic === 'Salud' ? 'alta' : 'media',
            'message' => mb_substr($message, 0, 5000), 'owner_id' => $owner->id], $actor, $owner);
    }

    private function create(array $fields, User $actor, User $owner): SupportTicket
    {
        return DB::transaction(function () use ($fields, $actor, $owner) {
            $ticket = SupportTicket::create($fields + ['code' => $this->sequences->next('support_ticket'), 'status' => 'abierta', 'created_by_user_id' => $actor->id,
                'first_response_due_at' => now()->addHours(self::SLA_HOURS)]);
            SupportTicketEvent::create(['ticket_id' => $ticket->id, 'occurred_at' => now(), 'user_id' => $actor->id, 'message' => 'Solicitud creada desde el '.($ticket->source === 'portal_cliente' ? 'portal del cliente' : 'portal del pasajero')]);
            $this->audit->log('Ticket soporte', $ticket->code, 'crear', null, "{$ticket->subject} · {$ticket->category} · {$ticket->source}", actor: $actor);
            $this->notify->push('soporte.ticket', 'portal', $owner, "Nueva solicitud {$ticket->code}: {$ticket->subject}", mb_substr($ticket->message, 0, 140),
                ['client_id' => $ticket->client_id, 'related_type' => 'support_ticket', 'related_id' => $ticket->id]);

            return $ticket;
        });
    }

    public static function row(SupportTicket $t): array
    {
        return ['id' => $t->id, 'code' => $t->code, 'subject' => $t->subject, 'category' => $t->category, 'topic' => $t->topic, 'priority' => $t->priority,
            'status' => $t->status, 'status_label' => SupportTicket::STATUS_LABELS[$t->status], 'message' => $t->message, 'reservation' => $t->reservation?->code,
            'created_at' => $t->created_at->toIso8601String(), 'due_at' => $t->first_response_due_at?->toIso8601String(), 'owner' => $t->owner?->name];
    }
}
