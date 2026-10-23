<?php

namespace App\Http\Controllers\Api\Crm;

use App\Models\Crm\Activity;
use App\Models\Crm\Opportunity;
use App\Models\Crm\PipelineStage;
use App\Services\Crm\CrmCatalog;
use App\Services\Crm\CrmClock;
use App\Services\Crm\Linked\LinkedData;
use App\Services\Crm\OpportunityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/** Spec 01 §6.2. */
class OpportunityController extends CrmController
{
    public function stages(Request $request, CrmCatalog $catalog): JsonResponse
    {
        $tenant = $request->user()->tenant_id;
        $stages = Cache::remember("crm:stages:{$tenant}", 300, fn () => $catalog->stages()->values()->map(fn (PipelineStage $s) => [
            'code' => $s->code, 'name' => $s->name, 'default_probability' => $s->default_probability, 'sort_order' => $s->sort_order, 'is_closed' => $s->is_closed,
        ])->all());

        return response()->json(['data' => $stages]);
    }

    public function index(Request $request, CrmClock $clock): JsonResponse
    {
        $user = $this->actor($request);
        $scoped = fn () => $this->ownerFilter($this->access->opportunities(Opportunity::query(), $user), $request);

        $query = $scoped()->with('client:id,code,name')
            ->when($request->input('filter.stage'), fn ($q, $s) => $q->whereIn('stage', explode(',', $s)))
            ->when($request->input('filter.open') !== null, fn ($q) => filter_var($request->input('filter.open'), FILTER_VALIDATE_BOOLEAN)
                ? $q->whereIn('stage', Opportunity::OPEN_STAGES) : $q->whereIn('stage', Opportunity::CLOSED))
            ->when($request->input('filter.q'), function ($q, $term) {
                $like = $this->like($term);
                $q->where(fn ($q) => $q->whereRaw('lower(title) like ?', [$like])->orWhereRaw('lower(crm_opportunities.code) like ?', [$like])
                    ->orWhereRaw('lower(destination_label) like ?', [$like])
                    ->orWhereHas('client', fn ($c) => $c->whereRaw('lower(name) like ?', [$like])));
            });

        // KPIs (CRM-BR-48: consolidados en PEN).
        $all = $scoped()->get(['id', 'stage', 'amount', 'currency', 'probability', 'closed_at']);
        $open = $all->whereIn('stage', Opportunity::OPEN_STAGES);
        $won = $all->where('stage', Opportunity::WON);
        $lost = $all->where('stage', Opportunity::LOST);
        $monthStart = $clock->today()->startOfMonth();
        $pen = fn ($o) => $clock->toPen((float) $o->amount, $o->currency);
        $kpis = [
            'pipeline_pen' => round($open->sum($pen), 2),
            'weighted_pen' => round($open->sum(fn ($o) => $pen($o) * $o->probability / 100), 2),
            'open_count' => $open->count(),
            'won_month_pen' => round($won->filter(fn ($o) => $o->closed_at?->gte($monthStart))->sum($pen), 2),
            'won_month_count' => $won->filter(fn ($o) => $o->closed_at?->gte($monthStart))->count(),
            'close_rate' => $won->count() + $lost->count() > 0 ? round($won->count() / ($won->count() + $lost->count()) * 100, 1) : 0,
        ];

        if ($request->input('group_by') === 'stage') {
            $items = $query->orderBy('expected_close_date')->limit(500)->get();
            $this->present->preloadUsers($items->pluck('owner_id'));
            $groups = [];
            foreach (app(CrmCatalog::class)->stages() as $stage) {
                $rows = $items->where('stage', $stage->code)->values();
                $groups[] = [
                    'stage' => $stage->code, 'name' => $stage->name, 'default_probability' => $stage->default_probability,
                    'count' => $rows->count(), 'total_pen' => round($rows->sum($pen), 2),
                    'items' => $rows->map(fn ($o) => $this->present->opportunity($o))->all(),
                ];
            }

            return response()->json(['data' => $groups, 'meta' => ['kpis' => $kpis]]);
        }

        $filtered = (clone $query)->get(['amount', 'currency', 'probability']);
        $totals = [
            'amount_pen' => round($filtered->sum($pen), 2),
            'weighted_pen' => round($filtered->sum(fn ($o) => $pen($o) * $o->probability / 100), 2),
        ];

        return response()->json($this->paginate($query->orderByDesc('created_at')->orderByDesc('id'), $request,
            fn (Opportunity $o) => $this->present->opportunity($o), ['kpis' => $kpis, 'totals' => $totals]));
    }

    public function store(Request $request, OpportunityService $service, CrmClock $clock): JsonResponse
    {
        $data = $request->validate($this->rules($clock, creating: true));
        $opportunity = $service->create($data, $this->actor($request));

        return response()->json(['data' => $this->present->opportunity($opportunity->load('client'))], 201);
    }

    public function show(Request $request, string $code, LinkedData $linked): JsonResponse
    {
        $opportunity = $this->find($code)->load('client', 'lead');
        $user = $this->actor($request);
        $this->access->ensureOpportunity($opportunity, $user);

        $quotes = $linked->quotesForOpportunity($opportunity->id);
        $activities = Activity::query()->where(function ($q) use ($opportunity, $quotes) {
            $q->where(fn ($q) => $q->where('related_type', 'opportunity')->where('related_id', $opportunity->id));
            if ($opportunity->lead_id) {
                $q->orWhere(fn ($q) => $q->where('related_type', 'lead')->where('related_id', $opportunity->lead_id));
            }
            if ($quotes !== []) {
                $q->orWhere(fn ($q) => $q->where('related_type', 'quote')->whereIn('related_id', array_column($quotes, 'id')));
            }
        })->orderBy('due_at')->get();

        return response()->json(['data' => $this->present->opportunity($opportunity) + [
            'lead' => $opportunity->lead?->only(['id', 'code', 'full_name', 'status']),
            'quotes' => $quotes,
            'activities' => $this->present->many($activities, 'activity'),
            'history' => $this->history([
                'Oportunidad' => [$opportunity->code],
                'Lead' => [$opportunity->lead?->code],
                'Cotización' => array_column($quotes, 'code'),
            ]),
        ], 'meta' => ['permissions' => [
            'manage' => true,
            'reassign' => $this->access->isSupervisor($user),
            'create_quote' => ! $opportunity->isClosed() && $user->hasPermissionTo('quotes.create'),
            'activity' => $user->hasPermissionTo('crm.activities.manage'),
        ]]]);
    }

    public function update(Request $request, string $code, OpportunityService $service, CrmClock $clock): JsonResponse
    {
        $opportunity = $this->find($code)->assertIfMatch($request);
        $data = $request->validate($this->rules($clock, creating: false));

        return response()->json(['data' => $this->present->opportunity($service->update($opportunity, $data, $this->actor($request))->load('client'))]);
    }

    public function stage(Request $request, string $code, OpportunityService $service): JsonResponse
    {
        $opportunity = $this->find($code)->assertIfMatch($request);
        $data = $request->validate([
            'stage' => ['required', Rule::in([...Opportunity::OPEN_STAGES, ...Opportunity::CLOSED])],
            // CRM-BR-24: motivo obligatorio al perder.
            'lost_reason_code' => 'required_if:stage,perdida|nullable|string|max:40',
            'lost_detail' => 'nullable|string|max:1000',
            'confirm_reopen' => 'boolean',
        ], ['lost_reason_code.required_if' => 'PROC-006: indique el motivo de pérdida']);
        $result = $service->changeStage($opportunity, $data['stage'], $this->actor($request), $data['lost_reason_code'] ?? null,
            $data['lost_detail'] ?? null, (bool) ($data['confirm_reopen'] ?? false));

        return response()->json(['data' => $this->present->opportunity($result['opportunity']->load('client')), 'meta' => ['warnings' => $result['warnings']]]);
    }

    public function reassign(Request $request, string $code, OpportunityService $service): JsonResponse
    {
        $opportunity = $this->find($code)->assertIfMatch($request);
        $data = $request->validate(['owner_id' => 'required|uuid', 'note' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->present->opportunity($service->reassign($opportunity, $data['owner_id'], $data['note'] ?? null, $this->actor($request))->load('client'))]);
    }

    private function find(string $code): Opportunity
    {
        return Opportunity::where('code', $code)->firstOrFail();
    }

    /** CRM-BR-20. */
    private function rules(CrmClock $clock, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $rules = [
            'title' => "{$req}|string|max:200",
            'amount' => "{$req}|numeric|gt:0|max:999999999999",
            'currency' => "{$req}|in:PEN,USD",
            'expected_close_date' => $creating ? 'required|date|after_or_equal:'.$clock->today()->toDateString() : 'sometimes|date',
            'destination_label' => 'nullable|string|max:120',
            'pax' => 'nullable|integer|min:0|max:999',
            'notes' => 'nullable|string|max:5000',
        ];

        return $creating
            ? $rules + ['client_id' => 'required|uuid', 'owner_id' => 'nullable|uuid', 'stage' => ['nullable', Rule::in(Opportunity::OPEN_STAGES)], 'origin' => 'nullable|in:Manual,Fidelización']
            : $rules + ['probability' => 'sometimes|integer|min:0|max:100'];
    }
}
