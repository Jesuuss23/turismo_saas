<?php

namespace App\Http\Controllers\Api\Crm;

use App\Models\Crm\Activity;
use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Crm\Opportunity;
use App\Services\Crm\ActivityService;
use App\Services\Crm\CrmClock;
use App\Services\Crm\CrmUsers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Spec 01 §6.3. */
class ActivityController extends CrmController
{
    public function index(Request $request, CrmClock $clock): JsonResponse
    {
        $user = $this->actor($request);
        $scoped = fn () => $this->ownerFilter($this->access->activities(Activity::query(), $user), $request);
        $now = now();
        $todayStart = $clock->today()->utc();
        $todayEnd = $clock->today()->endOfDay()->utc();

        $query = $scoped()
            ->when($request->input('filter.type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->input('filter.related_type'), fn ($q, $t) => $q->where('related_type', $t)->where('related_id', $request->input('filter.related_id')))
            ->when($request->input('filter.from'), fn ($q, $d) => $q->where('due_at', '>=', Carbon::parse($d, $clock->timezone())->startOfDay()->utc()))
            ->when($request->input('filter.to'), fn ($q, $d) => $q->where('due_at', '<=', Carbon::parse($d, $clock->timezone())->endOfDay()->utc()))
            ->when($request->input('filter.status'), fn ($q, $s) => match ($s) {
                'vencida' => $q->where('status', '<>', Activity::DONE)->where('due_at', '<', $now),
                'hoy' => $q->where('status', '<>', Activity::DONE)->whereBetween('due_at', [$now, $todayEnd]),
                'proximas' => $q->where('status', '<>', Activity::DONE)->where('due_at', '>', $todayEnd),
                'completada' => $q->where('status', Activity::DONE),
                'abiertas' => $q->where('status', '<>', Activity::DONE),
                default => $q,
            })
            ->orderBy('due_at')->orderBy('id');

        $kpis = [
            'overdue' => $scoped()->where('status', '<>', Activity::DONE)->where('due_at', '<', $now)->count(),
            'today' => $scoped()->where('status', '<>', Activity::DONE)->whereBetween('due_at', [$todayStart, $todayEnd])->count(),
            'next_7_days' => $scoped()->where('status', '<>', Activity::DONE)->whereBetween('due_at', [$now, $clock->today()->addDays(7)->endOfDay()->utc()])->count(),
            'completed_7_days' => $scoped()->where('status', Activity::DONE)->where('completed_at', '>=', $clock->today()->subDays(7)->utc())->count(),
        ];

        return response()->json($this->paginate($query, $request, fn (Activity $a) => $this->present->activity($a), ['kpis' => $kpis]));
    }

    /** Opciones visibles para el actor en el select de relación (CRM-BR-28/47). */
    public function relatedOptions(Request $request): JsonResponse
    {
        $type = $request->validate(['type' => 'required|in:lead,opportunity,client,quote', 'q' => 'nullable|string|max:80'])['type'];
        $user = $this->actor($request);
        $like = $request->filled('q') ? $this->like($request->input('q')) : null;

        $rows = match ($type) {
            'lead' => $this->access->leads(Lead::query(), $user)->where('status', '<>', Lead::DISCARDED)
                ->when($this->access->ownOnly($user), fn ($q) => $q->where('owner_id', $user->id))
                ->when($like, fn ($q) => $q->where(fn ($q) => $q->whereRaw('lower(full_name) like ?', [$like])->orWhereRaw('lower(code) like ?', [$like])))
                ->orderByDesc('created_at')->limit(50)->get(['id', 'code', 'full_name'])->map(fn ($l) => ['id' => $l->id, 'code' => $l->code, 'label' => "{$l->code} · {$l->full_name}"]),
            'opportunity' => $this->access->opportunities(Opportunity::query(), $user)
                ->when($like, fn ($q) => $q->where(fn ($q) => $q->whereRaw('lower(title) like ?', [$like])->orWhereRaw('lower(code) like ?', [$like])))
                ->orderByDesc('created_at')->limit(50)->get(['id', 'code', 'title'])->map(fn ($o) => ['id' => $o->id, 'code' => $o->code, 'label' => "{$o->code} · {$o->title}"]),
            'client' => $this->access->clients(Client::query(), $user)
                ->when($like, fn ($q) => $q->where(fn ($q) => $q->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(code) like ?', [$like])))
                ->orderBy('name')->limit(50)->get(['id', 'code', 'name'])->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'label' => "{$c->code} · {$c->name}"]),
            default => collect(), // Cotizaciones: spec 03
        };

        return response()->json(['data' => $rows->values()]);
    }

    public function store(Request $request, ActivityService $service): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(Activity::TYPES)],
            'subject' => 'required|string|max:200',
            'description' => 'nullable|string|max:5000',
            'related_type' => ['nullable', Rule::in(Activity::RELATED_TYPES)],
            'related_id' => 'nullable|uuid',
            'owner_id' => 'nullable|uuid',
            'due_at' => 'required|date',
        ]);

        return response()->json(['data' => $this->present->activity($service->create($data, $this->actor($request)))], 201);
    }

    public function show(Request $request, string $code): JsonResponse
    {
        $activity = $this->find($code);
        $this->access->ensureActivity($activity, $this->actor($request));

        return response()->json(['data' => $this->present->activity($activity) + [
            'related_label' => $this->relatedLabel($activity),
            'history' => $this->history(['Actividad' => [$activity->code]]),
        ]]);
    }

    public function complete(Request $request, string $code, ActivityService $service): JsonResponse
    {
        $activity = $this->find($code);
        // Próxima actividad sin asunto = no programar otra (la UI la propone activada por defecto).
        if (is_array($request->input('next')) && blank($request->input('next.subject'))) {
            $request->request->remove('next');
            $request->json()->remove('next');
        }
        $data = $request->validate([
            'result' => 'required|string|max:5000',
            'next' => 'nullable|array',
            'next.type' => ['nullable', Rule::in(Activity::TYPES)],
            'next.subject' => 'required_with:next|string|max:200',
            'next.due_at' => 'required_with:next|date',
        ], ['result.required' => 'Registre el resultado de la actividad']);
        $result = $service->complete($activity, $data['result'], $data['next'] ?? null, $this->actor($request));

        return response()->json(['data' => [
            'activity' => $this->present->activity($result['activity']),
            'next' => $result['next'] ? $this->present->activity($result['next']) : null,
            'lead_contacted' => $result['lead_contacted'],
        ]]);
    }

    public function reschedule(Request $request, string $code, ActivityService $service): JsonResponse
    {
        $activity = $this->find($code);
        $data = $request->validate(['due_at' => 'required|date', 'reason' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->present->activity($service->reschedule($activity, $data['due_at'], $data['reason'] ?? null, $this->actor($request)))]);
    }

    public function reassign(Request $request, string $code, ActivityService $service): JsonResponse
    {
        $activity = $this->find($code);
        $data = $request->validate(['owner_id' => 'required|uuid', 'note' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->present->activity($service->reassign($activity, $data['owner_id'], $data['note'] ?? null, $this->actor($request)))]);
    }

    /** Responsables elegibles (§7.3 selector "Responsable"). */
    public function owners(Request $request, CrmUsers $users): JsonResponse
    {
        $user = $this->actor($request);
        if ($this->access->ownOnly($user)) {
            return response()->json(['data' => [['id' => $user->id, 'code' => $user->code, 'name' => $user->name, 'role' => 'sales']], 'meta' => ['fixed' => true]]);
        }

        return response()->json(['data' => $users->advisors($request->boolean('include_operations'))->map(fn ($u) => [
            'id' => $u->id, 'code' => $u->code, 'name' => $u->name, 'role' => $u->roleName(),
        ])->values(), 'meta' => ['fixed' => false]]);
    }

    private function find(string $code): Activity
    {
        return Activity::where('code', $code)->firstOrFail();
    }

    private function relatedLabel(Activity $a): ?array
    {
        $row = match ($a->related_type) {
            'lead' => Lead::whereKey($a->related_id)->first(['id', 'code', 'full_name as name']),
            'opportunity' => Opportunity::whereKey($a->related_id)->first(['id', 'code', 'title as name']),
            'client' => Client::whereKey($a->related_id)->first(['id', 'code', 'name']),
            default => null,
        };

        return $row?->only(['id', 'code', 'name']);
    }
}
