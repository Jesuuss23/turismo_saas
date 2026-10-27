<?php

namespace App\Services\Collab;

use App\Models\Collab\Conversation;
use App\Models\Collab\InboundEvent;
use App\Models\Collab\Message;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Notify;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `ProcessInboundMessage` (§8, INB-BR-08): registra el evento entrante, es idempotente por `external_event_id` /
 * `external_message_id`, crea o reabre la conversación y notifica. Un error queda en el evento y no bloquea al resto (Regla 21).
 */
class InboundProcessor
{
    public function __construct(
        private readonly InboxService $inbox,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /**
     * @param  array{external_message_id: string, thread_id?: ?string, name?: ?string, email?: ?string, phone?: ?string, subject?: ?string, body: string,
     *     at?: mixed, reservation_id?: ?string}  $msg
     * @return array{status: string, conversation?: string, message_id?: string}
     */
    public function process(string $channel, array $msg, ?string $eventId = null, array $raw = []): array
    {
        $eventId ??= $msg['external_message_id'];
        if (InboundEvent::where('channel', $channel)->where('external_event_id', $eventId)->exists()
            || Message::where('channel', $channel)->where('external_message_id', $msg['external_message_id'])->exists()) {
            return ['status' => 'duplicado'];
        }
        $event = InboundEvent::create(['channel' => $channel, 'external_event_id' => $eventId, 'payload' => $raw ?: $msg, 'received_at' => now(), 'status' => 'recibido']);

        try {
            $result = DB::transaction(fn () => $this->apply($channel, $msg));
            $event->forceFill(['status' => 'procesado', 'processed_at' => now()])->save();

            return ['status' => 'procesado'] + $result;
        } catch (Throwable $e) {
            report($e);
            $event->forceFill(['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 2000), 'processed_at' => now()])->save();

            return ['status' => 'error'];
        }
    }

    private function apply(string $channel, array $msg): array
    {
        $at = isset($msg['at']) ? Carbon::parse($msg['at']) : now();
        $thread = $msg['thread_id'] ?? null;
        $conversation = $thread ? Conversation::where('channel', $channel)->where('external_thread_id', $thread)->lockForUpdate()->first() : null;
        $created = false;
        if (! $conversation) {
            $conversation = $this->inbox->open(['channel' => $channel, 'external_thread_id' => $thread, 'contact_name' => $msg['name'] ?? null,
                'contact_email' => $msg['email'] ?? null, 'contact_phone' => $msg['phone'] ?? null, 'subject' => $msg['subject'] ?? null,
                'reservation_id' => $msg['reservation_id'] ?? null, 'at' => $at]);
            $created = true;
        } elseif ($conversation->status !== 'abierta') {
            // §4.2: pendiente/resuelta + mensaje entrante → abierta (reapertura automática).
            $before = CollabCatalog::CONVERSATION_STATUS[$conversation->status];
            $conversation->forceFill(['status' => 'abierta', 'resolved_at' => null, 'resolved_by' => null])->save();
            $this->inbox->system($conversation, $before === 'Resuelta' ? 'Reabierta por un mensaje entrante del cliente' : 'El cliente respondió');
            $this->audit->log('Conversación', $conversation->code, 'mensaje_entrante', $before, 'Abierta', system: true);
        }
        $message = $this->inbox->inbound($conversation, trim($msg['body']) ?: '(mensaje sin texto)', $msg['external_message_id'], $at);

        $assignee = $conversation->assignee_id ? User::find($conversation->assignee_id) : null;
        $recipients = $assignee ? collect([$assignee]) : CatalogRecipients::managers();
        foreach ($recipients as $user) {
            $this->notify->push($created ? 'inbox.nueva' : 'inbox.mensaje', 'portal', $user,
                ($created ? 'Nueva conversación ' : 'Nuevo mensaje en ')."{$conversation->code} · {$conversation->contact_name}", mb_substr($message->body, 0, 140), $this->inbox->ref($conversation));
        }

        return ['conversation' => $conversation->code, 'message_id' => $message->id];
    }

    /** `ProcessDeliveryStatus`: entregado/leído/fallido del proveedor. */
    public function deliveryStatus(string $channel, string $externalId, string $status, ?string $reason = null): bool
    {
        $map = ['sent' => 'enviado', 'delivered' => 'entregado', 'read' => 'leido', 'failed' => 'fallido'];
        $message = Message::where('channel', $channel)->where('external_message_id', $externalId)->first();
        if (! $message || ! isset($map[$status])) {
            return false;
        }
        $message->forceFill(['delivery_status' => $map[$status], 'failure_reason' => $status === 'failed' ? $reason : null])->save();

        return true;
    }
}
