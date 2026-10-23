<?php

namespace App\Http\Controllers\Api\Crm;

use App\Exceptions\ApiException;
use App\Models\Crm\Activity;
use App\Models\Crm\AfterSalesCase;
use App\Models\Crm\Client;
use App\Models\Crm\ClientContact;
use App\Models\Crm\Lead;
use App\Models\Crm\Opportunity;
use App\Models\Crm\Survey;
use App\Models\Sys\NotifMessage;
use App\Services\Crm\ClientDocument;
use App\Services\Crm\ClientService;
use App\Services\Crm\ContactNormalizer;
use App\Services\Crm\Linked\LinkedData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/** Spec 01 §6.4: clientes y Customer 360. */
class ClientController extends CrmController
{
    public const TABS = ['leads', 'opportunities', 'quotes', 'reservations', 'passengers', 'receivables', 'payments', 'invoices', 'vouchers', 'communications', 'activities', 'timeline', 'audit', 'contacts'];

    public function index(Request $request, LinkedData $linked): JsonResponse
    {
        $user = $this->actor($request);
        $scoped = fn () => $this->ownerFilter($this->access->clients(Client::query(), $user), $request, 'crm_clients.owner_id');

        $query = $scoped()->with('primaryContact')
            ->when($request->input('filter.segment'), fn ($q, $s) => $q->where('segment_code', $s))
            ->when($request->input('filter.kind'), fn ($q, $k) => $q->where('kind', $k))
            ->when($request->input('filter.q'), function ($q, $term) {
                $like = $this->like($term);
                $q->where(fn ($q) => $q->whereRaw('lower(code) like ?', [$like])->orWhereRaw('lower(name) like ?', [$like])
                    ->orWhereRaw('lower(doc_number) like ?', [$like])->orWhereRaw('email_norm like ?', [$like])
                    ->orWhereRaw('phone like ?', [$like])->orWhereRaw('lower(city) like ?', [$like])
                    ->orWhereRaw('exists (select 1 from unnest(tags) t where lower(t) like ?)', [$like]));
            })
            ->orderBy('name')->orderBy('id');

        $all = $scoped()->get(['crm_clients.id', 'segment_code', 'nps_last']);
        $withNps = $all->whereNotNull('nps_last');
        $kpis = [
            'clients' => $all->count(),
            'corporate_b2b' => $all->whereIn('segment_code', ['corporativo', 'b2b'])->count(),
            'vip_groups' => $all->whereIn('segment_code', ['vip', 'grupos'])->count(),
            'nps' => self::nps($withNps->pluck('nps_last')),
        ];

        $ownerId = $this->access->ownOnly($user) ? $user->id : null;
        $page = $this->paginate($query, $request, fn (Client $c) => $c, ['kpis' => $kpis]);
        $stats = $linked->purchaseStats(collect($page['data'])->pluck('id')->all(), $ownerId);
        $page['data'] = collect($page['data'])->map(fn (Client $c) => $this->present->client($c, $stats[$c->id] ?? []))->all();

        return response()->json($page);
    }

    public function duplicates(Request $request, ClientService $clients): JsonResponse
    {
        $data = $request->validate([
            'doc_type' => 'nullable|in:DNI,RUC,PASAPORTE,CE', 'doc_number' => 'nullable|string|max:20',
            'email' => 'nullable|string|max:190', 'phone' => 'nullable|string|max:40', 'exclude' => 'nullable|uuid',
        ]);
        $hard = filled($data['doc_type'] ?? null) && filled($data['doc_number'] ?? null)
            ? $clients->findByDocument($data['doc_type'], $data['doc_number'], $data['exclude'] ?? null) : null;

        return response()->json(['data' => [
            'document' => $hard?->only(['id', 'code', 'name']),
            'contact' => $clients->softDuplicates($data['email'] ?? null, $data['phone'] ?? null, $data['exclude'] ?? null),
        ]]);
    }

    public function store(Request $request, ClientService $clients): JsonResponse
    {
        $data = $this->validated($request, creating: true);
        $client = $clients->create($data, $this->actor($request), (bool) ($data['confirm_duplicate'] ?? false), $data['contact'] ?? null);

        return response()->json(['data' => $this->present->client($client->load('primaryContact'))], 201);
    }

    public function show(Request $request, string $code, LinkedData $linked): JsonResponse
    {
        $client = $this->find($code)->load('primaryContact', 'contacts');
        $user = $this->actor($request);
        $this->access->ensureClient($client, $user);
        $ownerId = $this->access->ownOnly($user) ? $user->id : null;

        $stats = $linked->purchaseStats([$client->id], $ownerId)[$client->id] ?? [];
        $count = (int) ($stats['reservations_count'] ?? 0);
        $total = (float) ($stats['total_pen'] ?? 0);
        $openCases = AfterSalesCase::where('client_id', $client->id)->whereIn('status', [AfterSalesCase::OPEN, AfterSalesCase::IN_PROGRESS])->count();
        $partial = $this->access->partialView($client, $user);

        $alerts = [];
        if (($stats['overdue_pen'] ?? 0) > 0) {
            $alerts[] = ['type' => 'overdue_balance', 'level' => 'danger', 'message' => 'Saldo vencido. Coordine con Finanzas antes de nuevas reservas a crédito'];
        }
        if ($openCases > 0) {
            $alerts[] = ['type' => 'open_cases', 'level' => 'warning', 'message' => "{$openCases} caso(s) de postventa abierto(s)"];
        }
        if ($partial) {
            $alerts[] = ['type' => 'partial_view', 'level' => 'info', 'message' => 'Vista parcial: solo se muestran sus registros con este cliente'];
        }
        $readOnly = ! $user->hasPermissionTo('crm.clients.manage');
        if ($readOnly) {
            $alerts[] = ['type' => 'read_only', 'level' => 'info', 'message' => 'Modo consulta: su rol no puede modificar clientes'];
        }

        $wonDestinations = $this->scopedOpportunities($client, $user)->where('stage', Opportunity::WON)->whereNotNull('destination_label')->pluck('destination_label');

        return response()->json([
            'data' => $this->present->client($client, $stats) + [
                'contacts' => $client->contacts->map(fn (ClientContact $c) => $this->present->contact($c))->all(),
                'kpis' => [
                    'total_pen' => round($total, 2),
                    'reservations_count' => $count,
                    'average_ticket_pen' => $count > 0 ? round($total / $count, 2) : 0,
                    'balance_pen' => round((float) ($stats['balance_pen'] ?? 0), 2),
                    'nps' => $client->nps_last,
                ],
                'alerts' => $alerts,
                'partial_view' => $partial,
                'visited_destinations' => collect($stats['visited_destinations'] ?? [])->merge($wonDestinations)->unique()->values()->all(),
            ],
            'meta' => ['permissions' => [
                'manage' => ! $readOnly,
                'reassign' => $this->access->isSupervisor($user),
                'activity' => $user->hasPermissionTo('crm.activities.manage'),
                'opportunity' => $user->hasPermissionTo('crm.opportunities.manage'),
            ]],
        ]);
    }

    public function update(Request $request, string $code, ClientService $clients): JsonResponse
    {
        $client = $this->find($code)->assertIfMatch($request);
        $data = $this->validated($request, creating: false);

        return response()->json(['data' => $this->present->client($clients->update($client, $data, $this->actor($request), (bool) ($data['confirm_duplicate'] ?? false))->load('primaryContact'))]);
    }

    public function reassign(Request $request, string $code, ClientService $clients): JsonResponse
    {
        $client = $this->find($code)->assertIfMatch($request);
        $data = $request->validate(['owner_id' => 'required|uuid', 'note' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->present->client($clients->reassign($client, $data['owner_id'], $data['note'] ?? null, $this->actor($request)))]);
    }

    public function tab(Request $request, string $code, string $tab, LinkedData $linked): JsonResponse
    {
        if (! in_array($tab, self::TABS, true)) {
            throw ApiException::notFound();
        }
        $client = $this->find($code);
        $user = $this->actor($request);
        $this->access->ensureClient($client, $user);
        $ownerId = $this->access->ownOnly($user) ? $user->id : null;

        $data = match ($tab) {
            'contacts' => $client->contacts->map(fn ($c) => $this->present->contact($c))->all(),
            'leads' => $this->present->many($this->clientLeads($client, $user)->orderByDesc('created_at')->get(), 'lead'),
            'opportunities' => $this->present->many($this->scopedOpportunities($client, $user)->orderByDesc('created_at')->get(), 'opportunity'),
            'communications' => NotifMessage::where('client_id', $client->id)->orderByDesc('created_at')->limit(200)->get()
                ->map(fn (NotifMessage $m) => ['id' => $m->id, 'code' => $m->displayCode(), 'event' => $m->event, 'channel' => $m->channel,
                    'recipient' => $m->recipient, 'subject' => $m->subject, 'body' => $m->body, 'status' => $m->status, 'created_at' => $m->created_at, 'sent_at' => $m->sent_at])->all(),
            'activities' => $this->present->many($this->clientActivities($client, $user)->orderByDesc('due_at')->get(), 'activity'),
            'timeline' => $this->timeline($client, $user, $linked, $ownerId),
            'audit' => $this->audit($client, $user, $linked, $ownerId),
            default => $linked->clientTab($client->id, $tab, $ownerId),
        };

        return response()->json(['data' => $data, 'meta' => ['partial_view' => $this->access->partialView($client, $user)]]);
    }

    public function addContact(Request $request, string $code, ClientService $clients): JsonResponse
    {
        $client = $this->find($code);
        $this->access->ensureClient($client, $this->actor($request));
        $data = $request->validate([
            'name' => 'required|string|max:160', 'role' => 'nullable|string|max:120',
            'email' => ['nullable', 'string', 'max:190', 'regex:'.ContactNormalizer::EMAIL_PATTERN], 'phone' => 'nullable|string|max:40',
        ]);

        return response()->json(['data' => $this->present->contact($clients->addContact($client, $data, $this->actor($request)))], 201);
    }

    public function makePrimary(Request $request, string $code, string $contactId, ClientService $clients): JsonResponse
    {
        $client = $this->find($code);
        $this->access->ensureClient($client, $this->actor($request));
        $contact = ClientContact::where('client_id', $client->id)->whereKey($contactId)->firstOrFail();

        return response()->json(['data' => $this->present->contact($clients->makePrimary($client, $contact, $this->actor($request)))]);
    }

    public function communicate(Request $request, string $code, ClientService $clients): JsonResponse
    {
        $client = $this->find($code);
        $this->access->ensureClient($client, $this->actor($request));
        $data = $request->validate(['channel' => 'required|in:email,whatsapp,portal', 'subject' => 'required|string|max:200', 'body' => 'nullable|string|max:10000']);
        $clients->communicate($client, $data['channel'], $data['subject'], $data['body'] ?? null, $this->actor($request));

        return response()->json(['data' => ['queued' => true]], 202);
    }

    /** NPS = round((% promotores − % detractores)·100) (CRM-BR-42). */
    public static function nps(Collection $scores): ?int
    {
        $n = $scores->count();
        if ($n === 0) {
            return null;
        }
        $promoters = $scores->filter(fn ($s) => $s >= 9)->count();
        $detractors = $scores->filter(fn ($s) => $s <= 6)->count();

        return (int) round(($promoters - $detractors) / $n * 100);
    }

    private function find(string $code): Client
    {
        return Client::where('code', $code)->firstOrFail();
    }

    private function scopedOpportunities(Client $client, $user)
    {
        return $this->access->opportunities(Opportunity::where('client_id', $client->id), $user);
    }

    /** Leads del cliente: convertidos en él, de origen o marcados como duplicado suyo; Ventas: propios o sin asignar. */
    private function clientLeads(Client $client, $user)
    {
        return $this->access->leads(Lead::query(), $user)->where(fn ($q) => $q->where('converted_client_id', $client->id)
            ->orWhere(fn ($q) => $q->where('duplicate_of_type', 'client')->where('duplicate_of_id', $client->id))
            ->when($client->source_lead_id, fn ($q) => $q->orWhere('id', $client->source_lead_id)));
    }

    private function clientActivities(Client $client, $user)
    {
        $oppIds = $this->scopedOpportunities($client, $user)->pluck('id');
        $leadIds = $this->clientLeads($client, $user)->pluck('id');

        return $this->access->activities(Activity::query(), $user)->where(function ($q) use ($client, $oppIds, $leadIds) {
            $q->where(fn ($q) => $q->where('related_type', 'client')->where('related_id', $client->id))
                ->orWhere(fn ($q) => $q->where('related_type', 'opportunity')->whereIn('related_id', $oppIds))
                ->orWhere(fn ($q) => $q->where('related_type', 'lead')->whereIn('related_id', $leadIds));
        });
    }

    private function timeline(Client $client, $user, LinkedData $linked, ?string $ownerId): array
    {
        $events = [['type' => 'client', 'title' => "Alta de cliente {$client->code}", 'at' => $client->created_at, 'ref' => $client->code]];
        foreach ($this->clientLeads($client, $user)->get() as $lead) {
            $events[] = ['type' => 'lead', 'title' => "Lead {$lead->code} recibido", 'at' => $lead->created_at, 'ref' => $lead->code];
        }
        foreach ($this->scopedOpportunities($client, $user)->get() as $opp) {
            $events[] = ['type' => 'opportunity', 'title' => "Oportunidad {$opp->code} creada: {$opp->title}", 'at' => $opp->created_at, 'ref' => $opp->code];
            if ($opp->closed_at) {
                $events[] = ['type' => 'opportunity', 'title' => "Oportunidad {$opp->code} ".($opp->stage === Opportunity::WON ? 'ganada' : 'perdida'), 'at' => $opp->closed_at, 'ref' => $opp->code];
            }
        }
        foreach (Survey::where('client_id', $client->id)->get() as $survey) {
            $events[] = ['type' => 'survey', 'title' => "Encuesta {$survey->code}: NPS {$survey->score}", 'at' => $survey->responded_at, 'ref' => $survey->code];
        }
        foreach ($this->access->cases(AfterSalesCase::where('client_id', $client->id), $user)->get() as $case) {
            $events[] = ['type' => 'case', 'title' => "Caso {$case->code}: {$case->subject}", 'at' => $case->created_at, 'ref' => $case->code];
        }
        $events = [...$events, ...$linked->clientTimeline($client->id, $ownerId)];
        self::sortByDateDesc($events);

        return $events;
    }

    private function audit(Client $client, $user, LinkedData $linked, ?string $ownerId): array
    {
        $linkedCodes = fn (string $tab) => array_column($linked->clientTab($client->id, $tab, $ownerId), 'code');

        return $this->history([
            'Cliente' => [$client->code],
            'Lead' => $this->clientLeads($client, $user)->pluck('code')->all(),
            'Oportunidad' => $this->scopedOpportunities($client, $user)->pluck('code')->all(),
            'Reclamo' => $this->access->cases(AfterSalesCase::where('client_id', $client->id), $user)->pluck('code')->all(),
            'Cotización' => $linkedCodes('quotes'),
            'Reserva' => $linkedCodes('reservations'),
            'Pago' => $linkedCodes('payments'),
        ]);
    }

    /** CRM-BR-15/17. */
    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        // Contacto principal opcional: el formulario lo envía con todos los campos vacíos si no se completa.
        if (is_array($request->input('contact')) && collect($request->input('contact'))->filter(fn ($v) => filled($v))->isEmpty()) {
            $request->request->remove('contact');
            $request->json()->remove('contact');
        }
        $data = $request->validate([
            'kind' => "{$req}|in:persona,empresa",
            'name' => "{$req}|string|max:200",
            'doc_type' => ["{$req}", Rule::in(ClientDocument::TYPES)],
            'doc_number' => "{$req}|string|max:20",
            'email' => ['nullable', 'string', 'max:190', 'regex:'.ContactNormalizer::EMAIL_PATTERN],
            'phone' => 'nullable|string|max:40',
            'country' => 'nullable|string|max:80',
            'city' => 'nullable|string|max:80',
            'birth_date' => 'nullable|date|before:today',
            'segment_code' => "{$req}|string|max:40",
            'owner_id' => 'nullable|uuid',
            'tags' => 'nullable|array|max:20',
            'tags.*' => 'string|max:40',
            'confirm_duplicate' => 'boolean',
            'contact' => 'nullable|array',
            'contact.name' => 'required_with:contact|string|max:160',
            'contact.role' => 'nullable|string|max:120',
            'contact.email' => 'nullable|string|max:190',
            'contact.phone' => 'nullable|string|max:40',
        ], ['email.regex' => 'El correo no tiene un formato válido']);

        if ($creating && blank($data['email'] ?? null) && blank($data['phone'] ?? null)) {
            throw ApiException::invalid('email', 'Registre al menos correo o teléfono del cliente');
        }
        if (filled($data['phone'] ?? null) && ContactNormalizer::phoneDigits($data['phone']) < 7) {
            throw ApiException::invalid('phone', 'El teléfono debe tener al menos 7 dígitos');
        }
        if (($data['kind'] ?? null) === 'empresa') {
            unset($data['birth_date']);
        }

        return $data;
    }
}
