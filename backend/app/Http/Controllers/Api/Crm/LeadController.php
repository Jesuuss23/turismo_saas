<?php

namespace App\Http\Controllers\Api\Crm;

use App\Exceptions\ApiException;
use App\Models\Crm\Activity;
use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Crm\Opportunity;
use App\Services\Crm\ContactNormalizer;
use App\Services\Crm\CrmClock;
use App\Services\Crm\LeadConverter;
use App\Services\Crm\LeadDuplicateFinder;
use App\Services\Crm\LeadService;
use App\Services\Crm\Linked\LinkedData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Spec 01 §6.1. */
class LeadController extends CrmController
{
    public function index(Request $request, CrmClock $clock): JsonResponse
    {
        $user = $this->actor($request);
        $base = fn () => $this->access->leads(Lead::query(), $user);

        $query = $this->ownerFilter($base(), $request)
            ->when($request->input('filter.status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->input('filter.source'), fn ($q, $s) => $q->where('source_code', $s))
            ->when($request->input('filter.q'), function ($q, $term) {
                $like = $this->like($term);
                $q->where(fn ($q) => $q->whereRaw('lower(code) like ?', [$like])->orWhereRaw('lower(full_name) like ?', [$like])
                    ->orWhereRaw('lower(company) like ?', [$like])->orWhereRaw('email_norm like ?', [$like])
                    ->orWhereRaw('phone like ?', [$like])->orWhereRaw('lower(destination_label) like ?', [$like])
                    ->orWhereRaw('lower(notes) like ?', [$like]));
            })
            ->orderByDesc('created_at')->orderByDesc('id');

        // KPIs sobre el alcance del usuario (y el filtro Asesor del supervisor), sin los demás filtros.
        $scoped = fn () => $this->ownerFilter($base(), $request);
        $total = $scoped()->count();
        $duplicates = $scoped()->where('status', Lead::DUPLICATE)->count();
        $converted = $scoped()->where('status', Lead::CONVERTED)->count();
        $kpis = [
            'new_today' => $scoped()->where('created_at', '>=', $clock->today()->utc())->count(),
            'uncontacted' => $scoped()->where('status', Lead::NEW)->count(),
            'unassigned' => $scoped()->whereNull('owner_id')->whereIn('status', [Lead::NEW, Lead::CONTACTED])->count(),
            'qualified' => $scoped()->where('status', Lead::QUALIFIED)->count(),
            'conversion_rate' => $total - $duplicates > 0 ? round($converted / ($total - $duplicates) * 100, 1) : 0,
            'by_source' => $scoped()->select('source_code', DB::raw('count(*) as total'))->groupBy('source_code')->pluck('total', 'source_code'),
            'by_status' => $scoped()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status'),
        ];

        return response()->json($this->paginate($query, $request, fn (Lead $l) => $this->present->lead($l), ['kpis' => $kpis]));
    }

    public function duplicates(Request $request, LeadDuplicateFinder $finder): JsonResponse
    {
        $data = $request->validate(['email' => 'nullable|string|max:190', 'phone' => 'nullable|string|max:40', 'exclude' => 'nullable|uuid']);

        return response()->json(['data' => $finder->find($data['email'] ?? null, $data['phone'] ?? null, $data['exclude'] ?? null)]);
    }

    public function store(Request $request, LeadService $leads, LinkedData $linked): JsonResponse
    {
        $data = $this->validated($request, $linked, creating: true);
        $result = $leads->create($data, $this->actor($request), $data['duplicate_resolution'] ?? null, $data['merge_into'] ?? null);

        return response()->json([
            'data' => $this->present->lead($result['lead']),
            'meta' => ['merged' => $result['merged'], 'duplicates' => $result['duplicates']],
        ], $result['merged'] ? 200 : 201);
    }

    public function show(Request $request, string $code, LeadDuplicateFinder $finder): JsonResponse
    {
        $lead = $this->find($code);
        $user = $this->actor($request);
        $this->access->ensureLeadVisible($lead, $user);

        $activities = Activity::where('related_type', 'lead')->where('related_id', $lead->id)->orderBy('due_at')->get();
        $history = $this->history(['Lead' => [$lead->code]]);
        foreach ($activities->where('status', Activity::DONE) as $a) {
            $history[] = ['entity' => 'Actividad', 'entity_id' => $a->code, 'action' => 'completar', 'before' => $a->subject, 'after' => $a->result,
                'actor' => $this->present->user($a->completed_by) ?? ['name' => 'Sistema'], 'actor_role' => null, 'at' => $a->completed_at];
        }
        $history[] = ['entity' => 'Lead', 'entity_id' => $lead->code, 'action' => 'recibido', 'before' => null,
            'after' => $this->present->lead($lead)['source']['label'], 'actor' => $this->present->user($lead->created_by) ?? ['name' => 'Sistema'], 'actor_role' => null, 'at' => $lead->created_at];
        self::sortByDateDesc($history);

        $conversion = null;
        if ($lead->converted_client_id) {
            $client = Client::find($lead->converted_client_id);
            $opp = Opportunity::find($lead->converted_opportunity_id);
            $conversion = [
                'client' => $client?->only(['id', 'code', 'name']),
                'opportunity' => $opp?->only(['id', 'code', 'title', 'stage']),
            ];
        }

        return response()->json([
            'data' => $this->present->lead($lead) + [
                'qualification' => $this->present->qualification($lead->currentQualification),
                'duplicates' => $lead->isOpen() ? $finder->find($lead->email, $lead->phone, $lead->id) : [],
                'activities' => $this->present->many($activities, 'activity'),
                'history' => $history,
                'converted' => $conversion,
            ],
            'meta' => ['permissions' => $this->permissions($lead, $user)],
        ]);
    }

    public function update(Request $request, string $code, LeadService $leads, LinkedData $linked): JsonResponse
    {
        $lead = $this->find($code)->assertIfMatch($request);
        $result = $leads->update($lead, $this->validated($request, $linked, creating: false), $this->actor($request));

        return response()->json(['data' => $this->present->lead($result['lead']), 'meta' => ['duplicates' => $result['duplicates']]]);
    }

    public function take(Request $request, string $code, LeadService $leads): JsonResponse
    {
        $lead = $this->find($code);

        return response()->json(['data' => $this->present->lead($leads->take($lead, $this->actor($request)))]);
    }

    public function assign(Request $request, string $code, LeadService $leads): JsonResponse
    {
        $lead = $this->find($code)->assertIfMatch($request);
        $data = $request->validate(['owner_id' => 'required|uuid', 'schedule_first_contact' => 'boolean', 'note' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->present->lead(
            $leads->assign($lead, $data['owner_id'], (bool) ($data['schedule_first_contact'] ?? false), $data['note'] ?? null, $this->actor($request))
        )]);
    }

    public function status(Request $request, string $code, LeadService $leads): JsonResponse
    {
        $lead = $this->find($code)->assertIfMatch($request);
        // CRM-BR-13: convertido y descartado solo con sus acciones específicas.
        $data = $request->validate(['status' => ['required', Rule::in([Lead::NEW, Lead::CONTACTED, Lead::QUALIFIED])], 'note' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->present->lead($leads->changeStatus($lead, $data['status'], $data['note'] ?? null, $this->actor($request)))]);
    }

    public function qualify(Request $request, string $code, LeadService $leads): JsonResponse
    {
        $lead = $this->find($code)->assertIfMatch($request);
        $level = ['required', 'integer', Rule::in([0, 10, 18, 25])];
        $data = $request->validate([
            'budget' => $level, 'authority' => $level, 'need' => $level, 'timeline' => $level,
            'declared_amount' => 'nullable|numeric|min:0|max:999999999999',
            'currency' => 'nullable|in:PEN,USD',
            'notes' => 'nullable|string|max:2000',
            'mark_qualified' => 'boolean',
        ]);
        $result = $leads->qualify($lead, $data, $this->actor($request));

        return response()->json([
            'data' => $this->present->lead($result['lead']) + ['qualification' => $this->present->qualification($result['qualification'])],
            'meta' => ['reached_threshold' => $result['reached_threshold'], 'threshold' => Lead::QUALIFY_THRESHOLD],
        ]);
    }

    public function discard(Request $request, string $code, LeadService $leads): JsonResponse
    {
        $lead = $this->find($code)->assertIfMatch($request);
        $data = $request->validate(['reason_code' => 'required|string|max:40', 'detail' => 'nullable|string|max:1000']);

        return response()->json(['data' => $this->present->lead($leads->discard($lead, $data['reason_code'], $data['detail'] ?? null, $this->actor($request)))]);
    }

    public function reopen(Request $request, string $code, LeadService $leads): JsonResponse
    {
        $lead = $this->find($code)->assertIfMatch($request);

        return response()->json(['data' => $this->present->lead($leads->reopen($lead, $this->actor($request)))]);
    }

    public function markDuplicate(Request $request, string $code, LeadService $leads): JsonResponse
    {
        $lead = $this->find($code)->assertIfMatch($request);
        $data = $request->validate(['duplicate_of_type' => 'required|in:lead,client', 'duplicate_of_id' => 'required|uuid']);

        return response()->json(['data' => $this->present->lead($leads->markDuplicate($lead, $data['duplicate_of_type'], $data['duplicate_of_id'], $this->actor($request)))]);
    }

    public function merge(Request $request, string $code, LeadService $leads): JsonResponse
    {
        $lead = $this->find($code);
        $data = $request->validate(['source_lead_id' => 'required|uuid']);

        return response()->json(['data' => $this->present->lead($leads->merge($lead, $data['source_lead_id'], $this->actor($request)))]);
    }

    public function convert(Request $request, string $code, LeadConverter $converter, CrmClock $clock): JsonResponse
    {
        $lead = $this->find($code);
        $existing = $request->input('client_mode') === 'existing';
        $data = $request->validate([
            'client_mode' => 'required|in:new,existing',
            'client_id' => [Rule::requiredIf($existing), 'nullable', 'uuid'],
            'client' => [Rule::requiredIf(! $existing), 'array'],
            'client.kind' => [Rule::requiredIf(! $existing), 'in:persona,empresa'],
            'client.name' => 'nullable|string|max:200',
            'client.doc_type' => [Rule::requiredIf(! $existing), 'in:DNI,RUC,PASAPORTE,CE'],
            'client.doc_number' => [Rule::requiredIf(! $existing), 'nullable', 'string', 'max:20'],
            'client.segment' => 'nullable|string|max:40',
            'client.city' => 'nullable|string|max:80',
            'client.country' => 'nullable|string|max:80',
            'opportunity' => 'required|array',
            'opportunity.title' => 'required|string|max:200',
            'opportunity.stage' => 'nullable|in:calificacion,descubrimiento',
            // §12-8: > 0 también en la conversión (el prototipo aceptaba 0).
            'opportunity.amount' => 'required|numeric|gt:0|max:999999999999',
            'opportunity.currency' => 'required|in:PEN,USD',
            'opportunity.expected_close_date' => 'required|date|after_or_equal:'.$clock->today()->toDateString(),
            'opportunity.owner_id' => 'nullable|uuid',
            'schedule_discovery_meeting' => 'boolean',
        ]);
        $result = $converter->convert($lead, $data, $this->actor($request));

        return response()->json(['data' => [
            'lead' => $this->present->lead($lead->refresh()),
            'client' => $this->present->client($result['client']),
            'client_created' => $result['client_created'],
            'opportunity' => $this->present->opportunity($result['opportunity'], $result['client']),
            'activity' => $result['activity'] ? $this->present->activity($result['activity']) : null,
        ]], 201);
    }

    private function find(string $code): Lead
    {
        return Lead::where('code', $code)->firstOrFail();
    }

    /** CRM-BR-01..06. */
    private function validated(Request $request, LinkedData $linked, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $today = app(CrmClock::class)->today()->toDateString();
        $data = $request->validate([
            'full_name' => "{$req}|string|max:160",
            'company' => 'nullable|string|max:160',
            'email' => ['nullable', 'string', 'max:190', 'regex:'.ContactNormalizer::EMAIL_PATTERN],
            'phone' => ['nullable', 'string', 'max:40'],
            'channel_code' => "{$req}|string|max:40",
            'source_code' => "{$req}|string|max:40",
            'interest_code' => "{$req}|string|max:40",
            'destination_id' => 'nullable|uuid',
            'destination_label' => 'nullable|string|max:120',
            'product_id' => 'nullable|uuid',
            'travel_date' => 'nullable|date|after_or_equal:'.$today,
            'pax' => 'nullable|integer|min:0|max:999',
            'notes' => 'nullable|string|max:5000',
            'owner_id' => 'nullable|uuid',
            'duplicate_resolution' => 'nullable|in:merge,mark_duplicate,create_new',
            'merge_into' => 'nullable|uuid',
        ], [
            'email.regex' => 'El correo no tiene un formato válido',
            'travel_date.after_or_equal' => 'La fecha tentativa de viaje no puede ser anterior a hoy',
        ]);

        if ($creating && blank($data['email'] ?? null) && blank($data['phone'] ?? null)) {
            throw ApiException::invalid('email', 'Validación PROC-004: registre al menos correo o teléfono.');
        }
        if (filled($data['phone'] ?? null) && ContactNormalizer::phoneDigits($data['phone']) < 7) {
            throw ApiException::invalid('phone', 'El teléfono debe tener al menos 7 dígitos');
        }
        if (filled($data['product_id'] ?? null) && ! $linked->productSelectable($data['product_id'])) {
            throw ApiException::invalid('product_id', 'Solo se pueden elegir productos activos o aprobados');
        }
        if (filled($data['destination_id'] ?? null)) {
            $destination = $linked->destination($data['destination_id']) ?? throw ApiException::invalid('destination_id', 'El destino no existe');
            $data['destination_label'] = $destination['label'];
        }
        if (array_key_exists('email', $data)) {
            $data['email'] = blank($data['email']) ? null : trim($data['email']);
        }

        return $data;
    }

    /** Qué puede hacer el usuario en la ficha (§7.3), para mostrar/deshabilitar acciones. */
    private function permissions(Lead $lead, $user): array
    {
        $manage = $user->hasPermissionTo('crm.leads.manage');
        $own = ! $this->access->ownOnly($user) || $lead->owner_id === $user->id;

        return [
            'manage' => $manage && $own,
            'take' => $manage && $lead->owner_id === null && ! in_array($lead->status, [Lead::CONVERTED, Lead::DISCARDED], true),
            'assign' => $this->access->isSupervisor($user) && $lead->isOpen(),
            'convert' => $manage && $own && $user->hasPermissionTo('crm.opportunities.manage') && $lead->status === Lead::QUALIFIED,
            'activity' => $user->hasPermissionTo('crm.activities.manage') && $own,
        ];
    }
}
