<?php

namespace App\Services\Collab;

use App\Exceptions\ApiException;
use App\Models\Bookings\Reservation;
use App\Models\Channel\SupportTicket;
use App\Models\Channel\SupportTicketEvent;
use App\Models\Collab\ChannelAccount;
use App\Models\Collab\Conversation;
use App\Models\Collab\Message;
use App\Models\Collab\MessageAttachment;
use App\Models\Collab\ReplyTemplate;
use App\Models\Crm\Activity;
use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Crm\Opportunity;
use App\Models\Iam\User;
use App\Models\Quotes\Quote;
use App\Services\AuditLogger;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Crm\ActivityService;
use App\Services\Crm\ContactNormalizer;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\LeadService;
use App\Services\Notify;
use App\Services\Ops\PassengerApp;
use App\Services\SequenceService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bandeja omnicanal (spec 10 §4.2, INB-BR-01…11): respuesta con plantillas y ventana de WhatsApp, notas internas,
 * asignación, estados, vínculos CRM, lead/actividad desde la conversación y espejo de los tickets del portal.
 */
class InboxService
{
    /** Canal de conversación → [lead_channel, lead_source] del CRM (PROC-004). */
    private const LEAD_CODES = ['whatsapp' => ['whatsapp', 'whatsapp'], 'email' => ['email', 'email'], 'webchat' => ['web', 'formulario_web'],
        'form' => ['web', 'formulario_web'], 'portal' => ['web', 'formulario_web']];

    public function __construct(
        private readonly InboxScope $scope,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
        private readonly TenantContext $tenant,
    ) {}

    public function account(string $channel): ?ChannelAccount
    {
        return ChannelAccount::where('channel', $channel)->first();
    }

    public function slaMinutes(string $channel): int
    {
        return (int) ($this->account($channel)?->first_response_sla_minutes ?? CollabCatalog::FIRST_RESPONSE_SLA[$channel]);
    }

    /** Alta de conversación (webhook, formulario, ticket); reconoce al cliente o lead por email/teléfono. */
    public function open(array $data): Conversation
    {
        $email = ContactNormalizer::email($data['contact_email'] ?? null);
        $phone = ContactNormalizer::phone($data['contact_phone'] ?? null);
        $client = $data['client_id'] ?? null;
        $lead = $data['lead_id'] ?? null;
        if (! $client && ($email || $phone)) {
            $client = Client::where(fn ($q) => $q->when($email, fn ($w) => $w->orWhere('email_norm', $email))->when($phone, fn ($w) => $w->orWhere('phone_norm', $phone)))
                ->orderBy('created_at')->value('id');
            if (! $client) {
                $lead = Lead::where(fn ($q) => $q->when($email, fn ($w) => $w->orWhere('email_norm', $email))->when($phone, fn ($w) => $w->orWhere('phone_norm', $phone)))
                    ->whereNotIn('status', ['descartado'])->orderByDesc('created_at')->value('id');
            }
        }
        $channel = $data['channel'];
        $at = $data['at'] ?? now();

        $conversation = Conversation::create([
            'code' => $this->sequences->next('conversation'), 'channel' => $channel, 'channel_account_id' => $this->account($channel)?->id,
            'external_thread_id' => $data['external_thread_id'] ?? null, 'contact_name' => mb_substr($data['contact_name'] ?: 'Contacto sin nombre', 0, 160),
            'contact_email' => $data['contact_email'] ?? null, 'contact_phone' => $data['contact_phone'] ?? null,
            'subject' => mb_substr($data['subject'] ?: 'Consulta por '.CollabCatalog::CHANNELS[$channel], 0, 200), 'status' => 'abierta',
            'priority' => $data['priority'] ?? 'media', 'tags' => $data['tags'] ?? [], 'assignee_id' => $data['assignee_id'] ?? null,
            'client_id' => $client, 'lead_id' => $lead, 'reservation_id' => $data['reservation_id'] ?? null, 'support_ticket_id' => $data['support_ticket_id'] ?? null,
            'first_response_due_at' => $at->copy()->addMinutes($this->slaMinutes($channel)), 'last_message_at' => $at,
        ]);
        $this->audit->log('Conversación', $conversation->code, 'crear', null,
            CollabCatalog::CHANNELS[$channel]." · {$conversation->contact_name} · {$conversation->subject}".($client ? ' · cliente reconocido' : ''), system: true);

        return $conversation;
    }

    public function inbound(Conversation $c, string $body, ?string $externalId = null, $at = null): Message
    {
        $at ??= now();
        $message = Message::create(['conversation_id' => $c->id, 'channel' => $c->channel, 'direction' => 'in', 'body' => $body,
            'external_message_id' => $externalId, 'delivery_status' => null, 'sent_at' => $at]);
        $c->forceFill(['last_inbound_at' => $at, 'last_message_at' => $at, 'unread_count' => $c->unread_count + 1])->save();

        return $message;
    }

    public function system(Conversation $c, string $body, ?User $actor = null): Message
    {
        return Message::create(['conversation_id' => $c->id, 'channel' => $c->channel, 'direction' => 'system', 'author_user_id' => $actor?->id, 'body' => $body, 'sent_at' => now()]);
    }

    /** INB-BR-09: ticket del portal → conversación de canal `portal`. */
    public function fromTicket(SupportTicket $ticket): Conversation
    {
        $client = $ticket->client_id ? Client::find($ticket->client_id) : null;
        $c = $this->open(['channel' => 'portal', 'external_thread_id' => $ticket->code, 'contact_name' => $client?->name ?? 'Cliente del portal',
            'contact_email' => $client?->email, 'contact_phone' => $client?->phone, 'subject' => "{$ticket->code} · {$ticket->subject}",
            'priority' => $ticket->priority === 'alta' ? 'alta' : 'media', 'tags' => array_values(array_filter([$ticket->source, $ticket->category])),
            'assignee_id' => $ticket->owner_id, 'client_id' => $ticket->client_id, 'reservation_id' => $ticket->reservation_id, 'support_ticket_id' => $ticket->id]);
        $this->inbound($c, $ticket->message, "ticket:{$ticket->code}");
        if ($ticket->owner_id && $owner = User::find($ticket->owner_id)) {
            $this->notify->push('inbox.asignada', 'portal', $owner, "Conversación {$c->code}: {$c->subject}", mb_substr($ticket->message, 0, 140), $this->ref($c));
        }

        return $c;
    }

    /** Respuesta del agente (§4.2): 1.ª respuesta + autoasignación (INB-BR-03), ventana WhatsApp (INB-BR-02), adjuntos escaneados (INB-BR-11). */
    public function reply(Conversation $conversation, array $data, User $actor): Message
    {
        $template = null;
        if (! empty($data['template_id'])) {
            $template = ReplyTemplate::where('is_active', true)->whereKey($data['template_id'])->first() ?? throw ApiException::invalid('template_id', 'La plantilla no existe o está inactiva.');
        }
        $body = trim((string) ($data['body'] ?? ''));
        if ($body === '' && $template) {
            $body = $this->render($template->body, $conversation, $actor);
        }
        if ($body === '') {
            throw ApiException::invalid('body', 'Escriba la respuesta o elija una plantilla.');
        }
        if ($conversation->channel === 'whatsapp' && ! $this->windowOpen($conversation) && ! ($template?->is_whatsapp_approved)) {
            throw ApiException::unprocessable('INB-BR-02', 'Pasaron más de 24 h desde el último mensaje del cliente: en WhatsApp solo se envían plantillas aprobadas por Meta');
        }
        $attachments = $this->attachmentsFor($data['attachments'] ?? []);
        $resolve = filter_var($data['resolve'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return DB::transaction(function () use ($conversation, $body, $template, $attachments, $resolve, $actor) {
            $c = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $message = Message::create(['conversation_id' => $c->id, 'channel' => $c->channel, 'direction' => 'out', 'author_user_id' => $actor->id, 'body' => $body,
                'template_id' => $template?->id, 'external_message_id' => $c->channel === 'whatsapp' ? 'wamid.'.Str::random(24) : null,
                'delivery_status' => 'enviado', 'sent_at' => now()]);
            foreach ($attachments as $file) {
                $file->forceFill(['message_id' => $message->id])->save();
            }
            $changes = ['last_message_at' => now(), 'unread_count' => 0, 'status' => $resolve ? 'resuelta' : 'pendiente'];
            $notes = [];
            if ($c->first_response_at === null) {
                $changes['first_response_at'] = now();
                $notes[] = '1.ª respuesta '.(now()->lessThanOrEqualTo($c->first_response_due_at) ? 'dentro del SLA' : 'fuera del SLA');
            }
            if ($c->assignee_id === null) {
                $changes['assignee_id'] = $actor->id;
                $notes[] = "asignada a {$actor->name}";
            }
            if ($resolve) {
                $changes += ['resolved_at' => now(), 'resolved_by' => $actor->id];
            }
            $c->forceFill($changes)->save();
            $this->syncTicket($c, $resolve ? 'resuelta' : 'en_proceso', $body, $actor);
            $this->deliver($c, $body);
            $this->audit->log('Conversación', $c->code, $resolve ? 'responder_y_resolver' : 'responder', null,
                mb_substr($body, 0, 120).($template ? " · plantilla {$template->code}" : '').($notes ? ' · '.implode(' · ', $notes) : ''), actor: $actor);

            return $message->load('attachments');
        });
    }

    public function windowOpen(Conversation $c): bool
    {
        return $c->last_inbound_at !== null && $c->last_inbound_at->greaterThan(now()->subHours(24));
    }

    /** INB-BR-10: la nota interna no se envía ni se refleja en el ticket del portal. */
    public function note(Conversation $c, string $body, User $actor): Message
    {
        if (trim($body) === '') {
            throw ApiException::invalid('body', 'Escriba la nota interna.');
        }

        return Message::create(['conversation_id' => $c->id, 'channel' => $c->channel, 'direction' => 'internal', 'author_user_id' => $actor->id,
            'body' => trim($body), 'sent_at' => now()]);
    }

    /** INB-BR-04: Ventas solo toma (a sí mismo); Operaciones asigna entre Operaciones/Gerencia; Manager/Admin a cualquiera. */
    public function assign(Conversation $conversation, ?string $userId, User $actor): Conversation
    {
        $userId = $userId === 'me' || $userId === null ? $actor->id : $userId;
        $mode = $this->scope->mode($actor);
        if ($mode === 'own' && $userId !== $actor->id) {
            throw ApiException::forbidden('INB-BR-04', 'Ventas solo puede tomar la conversación (asignársela a sí mismo)', ['inbox.manage']);
        }
        $target = User::where('status', User::ACTIVE)->find($userId);
        if (! $target || ! $target->checkPermissionTo('inbox.manage')) {
            throw ApiException::invalid('user_id', 'El responsable debe ser un usuario activo con acceso a la bandeja.');
        }
        if ($mode === 'operations' && ! $target->hasAnyRole(['operations', 'manager', 'tenant_admin'])) {
            throw ApiException::forbidden('INB-BR-04', 'Operaciones asigna solo entre Operaciones y Gerencia', ['inbox.manage']);
        }

        return DB::transaction(function () use ($conversation, $target, $actor) {
            $c = $conversation;
            $before = $c->assignee?->name ?? 'Sin asignar';
            $c->forceFill(['assignee_id' => $target->id])->save();
            $this->system($c, $target->id === $actor->id ? "{$actor->name} tomó la conversación" : "{$actor->name} asignó la conversación a {$target->name}", $actor);
            $this->audit->log('Conversación', $c->code, 'asignar', $before, $target->name, actor: $actor);
            if ($target->id !== $actor->id) {
                $this->notify->push('inbox.asignada', 'portal', $target, "Conversación asignada {$c->code}: {$c->subject}", null, $this->ref($c));
            }

            return $c->fresh();
        });
    }

    public function resolve(Conversation $c, User $actor): Conversation
    {
        if ($c->status === 'resuelta') {
            throw ApiException::rule('INB-STATE', 'La conversación ya está resuelta');
        }

        return DB::transaction(function () use ($c, $actor) {
            $before = CollabCatalog::CONVERSATION_STATUS[$c->status];
            $c->forceFill(['status' => 'resuelta', 'resolved_at' => now(), 'resolved_by' => $actor->id, 'unread_count' => 0])->save();
            $this->system($c, "{$actor->name} resolvió la conversación", $actor);
            $this->syncTicket($c, 'resuelta', null, $actor);
            $this->audit->log('Conversación', $c->code, 'resolver', $before, 'Resuelta', actor: $actor);

            return $c->fresh();
        });
    }

    public function reopen(Conversation $c, User $actor): Conversation
    {
        if ($c->status !== 'resuelta') {
            throw ApiException::rule('INB-STATE', 'Solo se reabre una conversación resuelta');
        }

        return DB::transaction(function () use ($c, $actor) {
            $c->forceFill(['status' => 'abierta', 'resolved_at' => null, 'resolved_by' => null])->save();
            $this->system($c, "{$actor->name} reabrió la conversación", $actor);
            $this->syncTicket($c, 'en_proceso', null, $actor, 'Solicitud reabierta');
            $this->audit->log('Conversación', $c->code, 'reabrir', 'Resuelta', 'Abierta', actor: $actor);

            return $c->fresh();
        });
    }

    public function classify(Conversation $c, array $data, User $actor): Conversation
    {
        $changes = [];
        if (array_key_exists('priority', $data)) {
            $changes['priority'] = isset(CollabCatalog::PRIORITIES[$data['priority']]) ? $data['priority'] : throw ApiException::invalid('priority', 'Prioridad no válida.');
        }
        if (array_key_exists('tags', $data)) {
            $tags = collect(is_array($data['tags']) ? $data['tags'] : explode(',', (string) $data['tags']))->map(fn ($t) => mb_substr(trim((string) $t), 0, 40))->filter()->unique()->values()->all();
            $changes['tags'] = array_slice($tags, 0, 10);
        }
        if ($changes === []) {
            throw ApiException::invalid('priority', 'Indique la prioridad o las etiquetas.');
        }
        $before = "{$c->priority} · ".implode(', ', $c->tags ?? []);
        $c->forceFill($changes)->save();
        $this->audit->log('Conversación', $c->code, 'clasificar', $before, "{$c->priority} · ".implode(', ', $c->tags ?? []), actor: $actor);

        return $c->fresh();
    }

    /** INB-BR-06: solo clientes dentro del alcance (Ventas: su cartera); la reserva fija también el cliente. */
    public function link(Conversation $c, array $data, User $actor): Conversation
    {
        $access = app(CrmAccess::class);
        $changes = [];
        if (! empty($data['reservation_code'])) {
            $reservation = Reservation::where('code', $data['reservation_code'])->first() ?? throw ApiException::invalid('reservation_code', 'La reserva no existe.');
            $changes['reservation_id'] = $reservation->id;
            $data['client_code'] ??= $reservation->client?->code;
        }
        if (! empty($data['client_code'])) {
            $client = Client::where('code', $data['client_code'])->first() ?? throw ApiException::invalid('client_code', 'El cliente no existe.');
            if (! $access->clients(Client::whereKey($client->id), $actor)->exists()) {
                throw ApiException::forbidden('INB-BR-06', 'El cliente no está en su cartera', ['crm.clients.manage']);
            }
            $changes['client_id'] = $client->id;
        }
        if ($changes === []) {
            throw ApiException::invalid('client_code', 'Indique el cliente o la reserva a vincular.');
        }
        $c->forceFill($changes)->save();
        $c->load(['client', 'reservation']);
        $label = trim(($c->client ? "{$c->client->code} {$c->client->name}" : '').($c->reservation ? " · {$c->reservation->code}" : ''));
        $this->system($c, "Vinculada a {$label}", $actor);
        $this->audit->log('Conversación', $c->code, 'vincular_cliente', null, $label, actor: $actor);

        return $c->fresh();
    }

    /** INB-BR-05: PROC-004 con detección de duplicados; el lead guarda la conversación de origen. @return array{lead: Lead, merged: bool} */
    public function createLead(Conversation $c, array $data, User $actor): array
    {
        if ($c->lead_id) {
            throw ApiException::rule('INB-BR-05', 'La conversación ya tiene un lead vinculado');
        }
        [$channel, $source] = self::LEAD_CODES[$c->channel];
        $payload = array_filter([
            'full_name' => $data['full_name'] ?? $c->contact_name, 'email' => $data['email'] ?? $c->contact_email, 'phone' => $data['phone'] ?? $c->contact_phone,
            'channel_code' => $channel, 'source_code' => $source, 'interest_code' => $data['interest_code'] ?? 'paquete',
            'destination_label' => $data['destination_label'] ?? null, 'travel_date' => $data['travel_date'] ?? null, 'pax' => $data['pax'] ?? null,
            'owner_id' => $actor->id, 'notes' => trim("Origen: conversación {$c->code} ({$c->subject})\n".($data['notes'] ?? '')),
        ], fn ($v) => $v !== null && $v !== '');

        return DB::transaction(function () use ($c, $payload, $data, $actor) {
            $result = app(LeadService::class)->create($payload, $actor, $data['duplicate_resolution'] ?? null, $data['merge_into'] ?? null);
            $lead = $result['lead'];
            $c->forceFill(['lead_id' => $lead->id])->save();
            $this->system($c, ($result['merged'] ? 'Fusionada con el lead ' : 'Lead creado: ')."{$lead->code} {$lead->full_name}", $actor);
            $this->audit->log('Conversación', $c->code, 'vincular_lead', null, "{$lead->code} · ".($result['merged'] ? 'fusionado' : 'creado'), actor: $actor);

            return ['lead' => $lead, 'merged' => $result['merged']];
        });
    }

    /** INB-BR-07: exige vínculo a lead o cliente. */
    public function createActivity(Conversation $c, array $data, User $actor): Activity
    {
        [$type, $id] = $c->lead_id ? ['lead', $c->lead_id] : ($c->client_id ? ['client', $c->client_id] : [null, null]);
        if ($type === null) {
            throw ApiException::unprocessable('INB-BR-07', 'Vincule la conversación a un lead o cliente antes de crear la actividad');
        }
        foreach (['type', 'subject', 'due_at'] as $field) {
            if (empty($data[$field])) {
                throw ApiException::invalid($field, 'Complete tipo, asunto y fecha de la actividad.');
            }
        }

        return DB::transaction(function () use ($c, $data, $actor, $type, $id) {
            $activity = app(ActivityService::class)->create(['type' => $data['type'], 'subject' => $data['subject'], 'due_at' => $data['due_at'],
                'description' => trim(($data['description'] ?? '')."\nDesde la conversación {$c->code}"), 'related_type' => $type, 'related_id' => $id,
                'owner_id' => $data['owner_id'] ?? $actor->id], $actor);
            $this->system($c, "Actividad creada: {$activity->subject}", $actor);

            return $activity;
        });
    }

    /** «Crear cotización» navega al editor; aquí solo queda el evento (§6). */
    public function quoteIntent(Conversation $c, User $actor): array
    {
        $opportunity = $c->client_id ? Opportunity::where('client_id', $c->client_id)->whereNotIn('stage', Opportunity::CLOSED)->orderByDesc('updated_at')->first() : null;
        $this->system($c, "{$actor->name} inició una cotización".($opportunity ? " ({$opportunity->code})" : ''), $actor);
        $this->audit->log('Conversación', $c->code, 'crear_cotizacion', null, $opportunity?->code ?? 'sin oportunidad', actor: $actor);

        return ['url' => $opportunity ? "/cotizaciones/nueva/{$opportunity->code}" : '/cotizaciones/nueva'];
    }

    public function render(string $body, Conversation $c, User $actor): string
    {
        $tenant = $this->tenant->tenant();
        $quote = $c->quote_id ? Quote::find($c->quote_id) : ($c->client_id ? Quote::where('client_id', $c->client_id)->orderByDesc('created_at')->first() : null);
        $reservation = $c->reservation_id ? Reservation::find($c->reservation_id) : ($c->client_id ? Reservation::where('client_id', $c->client_id)->orderByDesc('travel_from')->first() : null);
        $vars = [
            'nombre' => Str::before(trim($c->contact_name), ' ') ?: $c->contact_name, 'asesor' => $actor->name,
            'empresa' => $tenant?->trade_name ?? $tenant?->legal_name ?? '', 'cotizacion' => $quote?->code ?? '—', 'reserva' => $reservation?->code ?? '—',
            'telefono_emergencia' => app(PassengerApp::class)->emergencyNumber(),
        ];

        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($m) => $vars[$m[1]] ?? $m[0], $body);
    }

    /** Subida simulada (URL firmada de S3 en producción) + `ScanAttachment` (INB-BR-11). */
    public function upload(array $data, User $actor): MessageAttachment
    {
        $name = trim((string) ($data['file_name'] ?? ''));
        $mime = (string) ($data['mime'] ?? '');
        $size = (int) ($data['size_bytes'] ?? 0);
        if ($name === '') {
            throw ApiException::invalid('file_name', 'Indique el nombre del archivo.');
        }
        if (! in_array($mime, CollabCatalog::ATTACHMENT_MIMES, true)) {
            throw ApiException::unprocessable('INB-BR-11', 'Tipo de archivo no permitido');
        }
        if ($size <= 0 || $size > CollabCatalog::ATTACHMENT_MAX_BYTES) {
            throw ApiException::unprocessable('INB-BR-11', 'El adjunto supera el máximo de 16 MB');
        }
        $id = (string) Str::uuid7();
        $ext = pathinfo($name, PATHINFO_EXTENSION) ?: 'bin';

        return MessageAttachment::create(['id' => $id, 'file_name' => mb_substr($name, 0, 200), 'mime' => $mime, 'size_bytes' => $size,
            'storage_path' => "tenants/{$this->tenant->id()}/inbox/{$id}.{$ext}", 'sha256' => hash('sha256', $id.$name),
            // Escaneo simulado: el archivo de prueba EICAR se marca infectado.
            'scan_status' => str_contains(mb_strtolower($name), 'eicar') ? 'infectado' : 'limpio', 'uploaded_by' => $actor->id]);
    }

    /** @return list<MessageAttachment> */
    private function attachmentsFor(array $ids): array
    {
        $files = [];
        foreach (array_slice($ids, 0, 5) as $id) {
            $file = MessageAttachment::whereKey($id)->whereNull('message_id')->first() ?? throw ApiException::invalid('attachments', 'Adjunto no encontrado.');
            if ($file->scan_status !== 'limpio') {
                throw ApiException::unprocessable('INB-BR-11', "El adjunto {$file->file_name} ".($file->scan_status === 'infectado' ? 'no superó el antivirus' : 'aún se está analizando'));
            }
            $files[] = $file;
        }

        return $files;
    }

    /** Respuesta y estado hacia el ticket del portal (INB-BR-09): solo texto público, nunca notas internas. */
    private function syncTicket(Conversation $c, string $status, ?string $publicReply, User $actor, ?string $log = null): void
    {
        if (! $c->support_ticket_id || ! ($ticket = SupportTicket::find($c->support_ticket_id))) {
            return;
        }
        if ($ticket->status !== $status) {
            $ticket->forceFill(['status' => $status])->save();
        }
        $message = $publicReply !== null ? "Respuesta de {$actor->name}: {$publicReply}" : ($log ?? 'Estado: '.SupportTicket::STATUS_LABELS[$status]);
        SupportTicketEvent::create(['ticket_id' => $ticket->id, 'occurred_at' => now(), 'user_id' => $actor->id, 'message' => mb_substr($message, 0, 2000)]);
        if ($status === 'resuelta' && ($email = $c->contact_email)) {
            $this->notify->push('soporte.resuelto', 'email', $email, "Su solicitud {$ticket->code} fue resuelta", $publicReply, ['client_id' => $ticket->client_id, 'related_type' => 'support_ticket', 'related_id' => $ticket->id]);
        }
    }

    /** `SendOutboundMessage` en sandbox: email/WhatsApp salen por los canales de notificación; web chat y portal se leen en la propia web. */
    private function deliver(Conversation $c, string $body): void
    {
        $data = ['client_id' => $c->client_id, 'related_type' => 'conversation', 'related_id' => $c->id];
        if ($c->channel === 'email' && $c->contact_email) {
            $this->notify->push('inbox.respuesta', 'email', $c->contact_email, "Re: {$c->subject}", $body, $data);
        } elseif ($c->channel === 'whatsapp' && $c->contact_phone) {
            $this->notify->push('inbox.respuesta', 'whatsapp', $c->contact_phone, $c->subject, $body, $data);
        }
    }

    /** `inbox:first-response-sla`: conversaciones sin 1.ª respuesta que vencen en ≤ 10 min o ya vencieron, una alerta por conversación. */
    public function firstResponseSla(): int
    {
        $count = 0;
        $due = Conversation::whereNull('first_response_at')->where('status', 'abierta')->whereNull('sla_alerted_at')
            ->where('first_response_due_at', '<=', now()->addMinutes(10))->with('assignee')->get();
        foreach ($due as $c) {
            $overdue = $c->first_response_due_at->isPast();
            $subject = ($overdue ? 'SLA de 1.ª respuesta vencido' : 'SLA de 1.ª respuesta por vencer')." · {$c->code} {$c->contact_name}";
            $recipients = collect([$c->assignee])->filter()->merge(CatalogRecipients::managers())->unique('id');
            foreach ($recipients as $user) {
                $this->notify->push('inbox.sla', 'portal', $user, $subject, CollabCatalog::CHANNELS[$c->channel].' · '.$c->subject, $this->ref($c));
            }
            $c->forceFill(['sla_alerted_at' => now()])->saveQuietly();
            $count++;
        }

        return $count;
    }

    public function ref(Conversation $c): array
    {
        return ['client_id' => $c->client_id, 'related_type' => 'conversation', 'related_id' => $c->id];
    }
}
