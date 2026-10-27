<?php

namespace App\Http\Controllers\Api\Collab;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Bookings\Reservation;
use App\Models\Collab\ChannelAccount;
use App\Models\Collab\Conversation;
use App\Models\Collab\Message;
use App\Models\Collab\MessageAttachment;
use App\Models\Collab\ReplyTemplate;
use App\Models\Crm\Lead;
use App\Models\Iam\User;
use App\Models\Quotes\Quote;
use App\Services\AuditLogger;
use App\Services\Collab\CollabCatalog;
use App\Services\Collab\InboundProcessor;
use App\Services\Collab\InboxScope;
use App\Services\Collab\InboxService;
use App\Services\SequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Spec 10 §6: bandeja omnicanal. inbox.view lee; inbox.manage actúa; el alcance por rol (INB-BR-04) se aplica en el servidor. */
class InboxController extends Controller
{
    public function __construct(private readonly InboxService $inbox, private readonly InboxScope $scope) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = trim((string) $request->input('filter.q', ''));
        $base = fn () => $this->scope->apply(Conversation::query(), $user);
        $rows = $base()
            ->when($request->input('filter.channel'), fn ($w, $c) => $w->where('channel', $c))
            ->when($request->input('filter.status'), fn ($w, $s) => $w->where('status', $s))
            ->when($request->input('filter.assignee') === 'me', fn ($w) => $w->where('assignee_id', $user->id))
            ->when($request->input('filter.assignee') === 'none', fn ($w) => $w->whereNull('assignee_id'))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('contact_name', 'ilike', "%{$q}%")->orWhere('subject', 'ilike', "%{$q}%")
                ->orWhere('code', 'ilike', "%{$q}%")->orWhere('contact_email', 'ilike', "%{$q}%")->orWhere('contact_phone', 'ilike', "%{$q}%")))
            ->with(['assignee:id,name', 'client:id,code,name'])->orderByDesc('last_message_at')->limit(200)->get();
        $last = Message::whereIn('conversation_id', $rows->pluck('id'))->whereIn('direction', ['in', 'out'])
            ->orderByDesc('sent_at')->get(['conversation_id', 'body', 'direction'])->unique('conversation_id')->keyBy('conversation_id');
        $all = $base()->get(['id', 'status', 'assignee_id', 'first_response_at', 'first_response_due_at']);

        return response()->json(['data' => $rows->map(fn (Conversation $c) => $this->row($c) + ['preview' => mb_substr((string) $last->get($c->id)?->body, 0, 90)])->all(), 'meta' => [
            'counters' => ['abierta' => $all->where('status', 'abierta')->count(), 'pendiente' => $all->where('status', 'pendiente')->count(),
                'mine' => $all->where('assignee_id', $user->id)->where('status', '<>', 'resuelta')->count(), 'unassigned' => $all->whereNull('assignee_id')->where('status', '<>', 'resuelta')->count(),
                'sla_overdue' => $all->filter(fn ($c) => $c->status === 'abierta' && $c->first_response_at === null && $c->first_response_due_at?->isPast())->count()],
            'scope' => InboxScope::MODE_LABELS[$this->scope->mode($user)], 'mode' => $this->scope->mode($user),
            'channels' => CollabCatalog::CHANNELS, 'statuses' => CollabCatalog::CONVERSATION_STATUS, 'can_manage' => $user->checkPermissionTo('inbox.manage'),
        ]]);
    }

    public function show(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->detail($this->find($request, $code), $request->user())]);
    }

    public function reply(Request $request, string $code): JsonResponse
    {
        $c = $this->find($request, $code);
        $this->inbox->reply($c, $request->all(), $request->user());

        return response()->json(['data' => $this->detail($c->fresh(), $request->user())], 202);
    }

    public function note(Request $request, string $code): JsonResponse
    {
        $c = $this->find($request, $code);
        $this->inbox->note($c, (string) $request->input('body'), $request->user());

        return response()->json(['data' => $this->detail($c->fresh(), $request->user())], 201);
    }

    public function assign(Request $request, string $code): JsonResponse
    {
        $c = $this->find($request, $code)->assertIfMatch($request);

        return response()->json(['data' => $this->detail($this->inbox->assign($c, $request->input('user_id'), $request->user()), $request->user())]);
    }

    public function resolve(Request $request, string $code): JsonResponse
    {
        $c = $this->find($request, $code)->assertIfMatch($request);

        return response()->json(['data' => $this->detail($this->inbox->resolve($c, $request->user()), $request->user())]);
    }

    public function reopen(Request $request, string $code): JsonResponse
    {
        $c = $this->find($request, $code)->assertIfMatch($request);

        return response()->json(['data' => $this->detail($this->inbox->reopen($c, $request->user()), $request->user())]);
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $c = $this->find($request, $code)->assertIfMatch($request);

        return response()->json(['data' => $this->detail($this->inbox->classify($c, $request->all(), $request->user()), $request->user())]);
    }

    public function link(Request $request, string $code): JsonResponse
    {
        $c = $this->find($request, $code);

        return response()->json(['data' => $this->detail($this->inbox->link($c, $request->all(), $request->user()), $request->user())]);
    }

    public function lead(Request $request, string $code): JsonResponse
    {
        $c = $this->find($request, $code);
        $result = $this->inbox->createLead($c, $request->all(), $request->user());

        return response()->json(['data' => $this->detail($c->fresh(), $request->user()), 'meta' => ['lead' => $result['lead']->code, 'merged' => $result['merged']]], 201);
    }

    public function activity(Request $request, string $code): JsonResponse
    {
        $c = $this->find($request, $code);
        $activity = $this->inbox->createActivity($c, $request->all(), $request->user());

        return response()->json(['data' => $this->detail($c->fresh(), $request->user()), 'meta' => ['activity' => $activity->id]], 201);
    }

    public function quoteIntent(Request $request, string $code): JsonResponse
    {
        if (! $request->user()->checkPermissionTo('quotes.create')) {
            throw ApiException::forbidden('FORBIDDEN', 'Crear cotización requiere quotes.create', ['quotes.create']);
        }

        return response()->json(['data' => $this->inbox->quoteIntent($this->find($request, $code), $request->user())]);
    }

    public function attachment(Request $request): JsonResponse
    {
        $file = $this->inbox->upload($request->all(), $request->user());

        return response()->json(['data' => ['id' => $file->id, 'file_name' => $file->file_name, 'scan_status' => $file->scan_status, 'size_bytes' => $file->size_bytes,
            'upload_url' => url("/api/v1/inbox/attachments/{$file->id}/upload").'?expires='.now()->addMinutes(15)->timestamp]], 201);
    }

    /** «Simular entrante» (demo del webhook): mismo procesador que los canales reales. */
    public function simulate(Request $request, InboundProcessor $processor): JsonResponse
    {
        $channel = (string) $request->input('channel', 'webchat');
        if (! in_array($channel, ['whatsapp', 'email', 'webchat', 'form'], true)) {
            throw ApiException::invalid('channel', 'Canal no válido.');
        }
        $body = trim((string) $request->input('body', ''));
        if ($body === '') {
            throw ApiException::invalid('body', 'Escriba el mensaje entrante.');
        }
        $id = 'sim-'.Str::uuid();
        $phone = $request->input('phone');
        $email = $request->input('email');
        $thread = $request->input('conversation_code')
            ? Conversation::where('code', $request->input('conversation_code'))->value('external_thread_id')
            : match ($channel) {
                'whatsapp' => $phone ? preg_replace('/\D/', '', $phone) : null, 'email' => $email, 'webchat' => 'web-'.Str::random(10), default => null
            };
        $result = $processor->process($channel, ['external_message_id' => $id, 'thread_id' => $thread, 'name' => $request->input('name'), 'email' => $email,
            'phone' => $phone, 'subject' => $request->input('subject'), 'body' => $body]);

        return response()->json(['data' => $result], 201);
    }

    public function channels(): JsonResponse
    {
        return response()->json(['data' => ChannelAccount::orderBy('channel')->get()->map(fn (ChannelAccount $a) => [
            'channel' => $a->channel, 'label' => CollabCatalog::CHANNELS[$a->channel], 'name' => $a->name, 'status' => $a->status,
            'first_response_sla_minutes' => $a->first_response_sla_minutes, 'phone_number_id' => $a->phone_number_id, 'mailbox' => $a->mailbox,
            'webhook' => match ($a->channel) {
                'whatsapp' => '/api/v1/integrations/whatsapp/webhook', 'email' => '/api/v1/integrations/email/inbound',
                'form', 'webchat' => '/api/v1/public/contact', default => 'Tickets del portal (interno)'
            },
            'has_secret' => $a->webhook_secret_encrypted !== null,
        ])->all()]);
    }

    // ── Plantillas ──────────────────────────────────────────────────────

    public function templates(): JsonResponse
    {
        return response()->json(['data' => ReplyTemplate::orderBy('code')->get()->map(fn (ReplyTemplate $t) => $this->template($t))->all(),
            'meta' => ['variables' => CollabCatalog::TEMPLATE_VARIABLES, 'channels' => CollabCatalog::CHANNELS]]);
    }

    public function storeTemplate(Request $request, SequenceService $sequences, AuditLogger $audit): JsonResponse
    {
        $data = $this->templateData($request->all());
        $t = ReplyTemplate::create($data + ['code' => $sequences->next('reply_template')]);
        $audit->log('Plantilla de respuesta', $t->code, 'crear', null, $t->name, actor: $request->user());

        return response()->json(['data' => $this->template($t)], 201);
    }

    public function updateTemplate(Request $request, AuditLogger $audit, string $template): JsonResponse
    {
        $t = ReplyTemplate::where('code', $template)->first() ?? throw ApiException::notFound();
        $before = $t->name;
        $t->fill($this->templateData($request->all() + $t->only(['name', 'body', 'channels', 'is_whatsapp_approved', 'whatsapp_template_name', 'is_active'])))->save();
        $audit->log('Plantilla de respuesta', $t->code, 'editar', $before, $t->name.($t->is_active ? '' : ' · inactiva'), actor: $request->user());

        return response()->json(['data' => $this->template($t)]);
    }

    /** §3.9: variables validadas contra la lista cerrada. */
    private function templateData(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $body = trim((string) ($data['body'] ?? ''));
        if ($name === '') {
            throw ApiException::invalid('name', 'Indique el nombre de la plantilla.');
        }
        if ($body === '') {
            throw ApiException::invalid('body', 'Escriba el texto de la plantilla.');
        }
        preg_match_all('/\{\{\s*([^}]*?)\s*\}\}/', $body, $m);
        $unknown = array_diff($m[1], CollabCatalog::TEMPLATE_VARIABLES);
        if ($unknown) {
            throw ApiException::invalid('body', 'Variables no permitidas: '.implode(', ', $unknown).'. Use '.implode(', ', array_map(fn ($v) => "{{{$v}}}", CollabCatalog::TEMPLATE_VARIABLES)).'.');
        }
        $channels = array_values(array_intersect(array_keys(CollabCatalog::CHANNELS), (array) ($data['channels'] ?? [])));
        if ($channels === []) {
            throw ApiException::invalid('channels', 'Elija al menos un canal.');
        }
        $approved = filter_var($data['is_whatsapp_approved'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return ['name' => mb_substr($name, 0, 120), 'body' => $body, 'channels' => $channels, 'is_whatsapp_approved' => $approved,
            'whatsapp_template_name' => $approved ? (trim((string) ($data['whatsapp_template_name'] ?? '')) ?: Str::slug($name, '_')) : null,
            'whatsapp_language' => $approved ? 'es_PE' : null, 'is_active' => filter_var($data['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN)];
    }

    private function template(ReplyTemplate $t): array
    {
        return ['id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'body' => $t->body, 'channels' => $t->channels, 'is_whatsapp_approved' => $t->is_whatsapp_approved,
            'whatsapp_template_name' => $t->whatsapp_template_name, 'is_active' => $t->is_active];
    }

    // ── Presentación ────────────────────────────────────────────────────

    private function find(Request $request, string $code): Conversation
    {
        $c = Conversation::where('code', $code)->first() ?? throw ApiException::notFound();
        $this->scope->ensureVisible($c, $request->user());

        return $c;
    }

    private function row(Conversation $c): array
    {
        return ['id' => $c->id, 'code' => $c->code, 'channel' => $c->channel, 'channel_label' => CollabCatalog::CHANNELS[$c->channel], 'contact_name' => $c->contact_name,
            'contact_email' => $c->contact_email, 'contact_phone' => $c->contact_phone, 'subject' => $c->subject, 'status' => $c->status,
            'status_label' => CollabCatalog::CONVERSATION_STATUS[$c->status], 'priority' => $c->priority, 'tags' => $c->tags ?? [],
            'assignee' => $c->assignee?->name, 'assignee_id' => $c->assignee_id, 'client' => $c->client?->name, 'unread' => $c->unread_count,
            'last_message_at' => $c->last_message_at?->toIso8601String(), 'first_response_due_at' => $c->first_response_due_at?->toIso8601String(),
            'first_response_at' => $c->first_response_at?->toIso8601String(),
            'sla' => $c->first_response_at ? ($c->first_response_at->lessThanOrEqualTo($c->first_response_due_at) ? 'cumplido' : 'incumplido')
                : ($c->status === 'abierta' ? ($c->first_response_due_at->isPast() ? 'vencido' : 'en_curso') : null),
            'lock_version' => $c->lock_version];
    }

    private function detail(Conversation $c, User $user): array
    {
        $c->load(['assignee:id,name', 'client', 'lead', 'reservation', 'quote', 'ticket', 'messages.author:id,name', 'messages.attachments']);
        $client = $c->client;
        $canManage = $user->checkPermissionTo('inbox.manage');
        $mode = $this->scope->mode($user);
        $assignees = $mode === 'own' ? collect([$user]) : User::where('status', User::ACTIVE)->orderBy('name')->get()
            ->filter(fn (User $u) => $u->checkPermissionTo('inbox.manage') && ($mode !== 'operations' || $u->hasAnyRole(['operations', 'manager', 'tenant_admin'])));
        $block = fn (string $perm, string $label) => $user->checkPermissionTo($perm) ? null : "{$label} requiere {$perm}";

        return $this->row($c) + [
            'messages' => $c->messages->map(fn (Message $m) => ['id' => $m->id, 'direction' => $m->direction, 'body' => $m->body, 'author' => $m->author?->name,
                'at' => $m->sent_at->toIso8601String(), 'delivery_status' => $m->delivery_status, 'template_id' => $m->template_id,
                'attachments' => $m->attachments->map(fn (MessageAttachment $a) => ['id' => $a->id, 'file_name' => $a->file_name, 'size_bytes' => $a->size_bytes, 'scan_status' => $a->scan_status])->all()])->all(),
            'window_open' => $c->channel !== 'whatsapp' || $this->inbox->windowOpen($c),
            'contact' => [
                'client' => $client ? ['code' => $client->code, 'name' => $client->name, 'email' => $client->email, 'phone' => $client->phone, 'segment' => $client->segment_code] : null,
                'lead' => $c->lead ? ['code' => $c->lead->code, 'name' => $c->lead->full_name, 'status' => $c->lead->status] : null,
                'reservation' => $c->reservation ? ['code' => $c->reservation->code, 'title' => $c->reservation->title, 'status' => $c->reservation->status,
                    'travel' => $c->reservation->travel_from?->toDateString().' → '.$c->reservation->travel_to?->toDateString()] : null,
                'ticket' => $c->ticket ? ['code' => $c->ticket->code, 'status' => $c->ticket->status, 'source' => $c->ticket->source] : null,
                'reservations' => $client ? Reservation::where('client_id', $client->id)->orderByDesc('travel_from')->limit(5)->get()
                    ->map(fn (Reservation $r) => ['code' => $r->code, 'title' => $r->title, 'status' => $r->status, 'travel_from' => $r->travel_from?->toDateString()])->all() : [],
                'quotes' => $client ? Quote::where('client_id', $client->id)->orderByDesc('created_at')->limit(5)->get()
                    ->map(fn (Quote $q) => ['code' => $q->code, 'title' => $q->title, 'status' => $q->status])->all() : [],
                'possible_leads' => ! $c->lead_id && ! $client ? Lead::where(fn ($w) => $w->when($c->contact_email, fn ($x) => $x->orWhere('email', $c->contact_email))
                    ->when($c->contact_phone, fn ($x) => $x->orWhere('phone', $c->contact_phone)))->limit(3)->pluck('code')->all() : [],
            ],
            'assignees' => $assignees->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()->all(),
            'templates' => ReplyTemplate::where('is_active', true)->orderBy('code')->get()->filter(fn (ReplyTemplate $t) => in_array($c->channel, $t->channels ?? [], true))
                ->map(fn (ReplyTemplate $t) => $this->template($t) + ['preview' => $this->inbox->render($t->body, $c, $user)])->values()->all(),
            'actions' => [
                'manage' => $canManage, 'reassign' => $canManage && $mode !== 'own',
                'lead' => $c->lead_id ? 'La conversación ya tiene un lead' : $block('crm.leads.manage', 'Crear lead'),
                'activity' => $block('crm.activities.manage', 'Crear actividad') ?? (! $c->lead_id && ! $c->client_id ? 'Vincule un lead o cliente primero (INB-BR-07)' : null),
                'quote' => $block('quotes.create', 'Crear cotización'),
                'link' => $block('crm.clients.manage', 'Vincular cliente'),
            ],
        ];
    }
}
