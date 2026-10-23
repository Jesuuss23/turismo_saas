<?php

namespace App\Http\Controllers\Api\Crm;

use App\Models\Crm\AfterSalesCase;
use App\Models\Crm\Client;
use App\Models\Crm\Opportunity;
use App\Models\Crm\Survey;
use App\Services\Crm\AfterSalesService;
use App\Services\Crm\CrmClock;
use App\Services\Crm\Linked\LinkedData;
use App\Services\Crm\LoyaltySegments;
use App\Services\Crm\OpportunityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Spec 01 §6.5: postventa (PROC-009) y fidelización (PROC-010). */
class AfterSalesController extends CrmController
{
    /** CRM-BR-45: destino sugerido para la recompra según el último destino visitado. */
    public const REBUY_MAP = [
        'Cusco' => 'Arequipa y Cañón del Colca',
        'Arequipa' => 'Cusco y Machu Picchu',
        'Ica' => 'Cusco y Valle Sagrado',
        'Machu Picchu' => 'Lago Titicaca — Puno',
        'Puno' => 'Paracas e Islas Ballestas',
    ];

    public const REBUY_DEFAULT = 'Cusco y Machu Picchu';

    public function trips(Request $request, LinkedData $linked): JsonResponse
    {
        $user = $this->actor($request);
        // §12-9: el alcance se aplica a ambas condiciones (completadas y en operación).
        $trips = $linked->tripsToClose($this->access->ownOnly($user) ? $user->id : null);
        $surveys = Survey::whereIn('reservation_id', array_filter(array_column($trips, 'id')))->get()->keyBy('reservation_id');

        return response()->json(['data' => array_map(fn ($t) => $t + [
            'survey' => isset($surveys[$t['id']]) ? $this->present->survey($surveys[$t['id']]) : null,
        ], $trips)]);
    }

    public function surveys(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $clientIds = $this->access->clients(Client::query(), $user)->select('crm_clients.id');
        $scoped = fn () => Survey::whereIn('client_id', $clientIds);
        $scores = $scoped()->pluck('score');

        $distribution = array_fill(0, 11, 0);
        foreach ($scores as $s) {
            $distribution[$s]++;
        }
        $stats = [
            'count' => $scores->count(),
            'nps' => ClientController::nps($scores),
            'average' => $scores->count() ? round($scores->avg(), 1) : null,
            'distribution' => $distribution,
            'promoters' => $scores->filter(fn ($s) => $s >= 9)->count(),
            'passives' => $scores->filter(fn ($s) => $s >= 7 && $s <= 8)->count(),
            'detractors' => $scores->filter(fn ($s) => $s <= 6)->count(),
        ];

        $query = $scoped()->with('client:id,code,name')->orderByDesc('responded_at')->orderByDesc('id');

        return response()->json($this->paginate($query, $request, fn (Survey $s) => $this->present->survey($s, $s->client), ['stats' => $stats]));
    }

    public function storeSurvey(Request $request, AfterSalesService $service): JsonResponse
    {
        $data = $request->validate([
            'client_id' => 'required|uuid',
            'reservation_id' => 'nullable|uuid',
            'channel_code' => 'required|string|max:40',
            'score' => 'required|integer|min:0|max:10',
            'comment' => 'nullable|string|max:5000',
        ]);
        $result = $service->recordSurvey($data, $this->actor($request));

        return response()->json([
            'data' => [
                'survey' => $this->present->survey($result['survey']),
                'case' => $result['case'] ? $this->present->case($result['case']) : null,
                'activity' => $result['activity'] ? $this->present->activity($result['activity']) : null,
            ],
            'meta' => ['warnings' => $result['warnings']],
        ], 201);
    }

    public function cases(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $query = $this->ownerFilter($this->access->cases(AfterSalesCase::query(), $user), $request, 'crm_cases.owner_id')
            ->with('client:id,code,name')
            ->when($request->input('filter.status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->input('filter.type'), fn ($q, $t) => $q->where('type_code', $t))
            ->when($request->input('filter.q'), function ($q, $term) {
                $like = $this->like($term);
                $q->where(fn ($q) => $q->whereRaw('lower(crm_cases.code) like ?', [$like])->orWhereRaw('lower(subject) like ?', [$like])
                    ->orWhereHas('client', fn ($c) => $c->whereRaw('lower(name) like ?', [$like])));
            })
            ->orderByDesc('created_at')->orderByDesc('id');

        $open = $this->access->cases(AfterSalesCase::query(), $user)->whereIn('status', [AfterSalesCase::OPEN, AfterSalesCase::IN_PROGRESS])->count();

        return response()->json($this->paginate($query, $request, fn (AfterSalesCase $c) => $this->present->case($c), ['kpis' => ['open' => $open]]));
    }

    public function storeCase(Request $request, AfterSalesService $service): JsonResponse
    {
        $data = $request->validate([
            'client_id' => 'required|uuid',
            'reservation_id' => 'nullable|uuid',
            'type_code' => 'required|string|max:40',
            'priority' => ['required', Rule::in(AfterSalesService::PRIORITIES)],
            'subject' => 'required|string|max:200',
            'description' => 'required|string|max:5000',
            'owner_id' => 'nullable|uuid',
        ]);

        return response()->json(['data' => $this->present->case($service->createCase($data, $this->actor($request))->load('client'))], 201);
    }

    public function showCase(Request $request, string $code): JsonResponse
    {
        $case = $this->findCase($code)->load('client', 'events');
        $user = $this->actor($request);
        $this->access->ensureCase($case, $user);

        return response()->json(['data' => $this->present->case($case, withEvents: true) + [
            'history' => $this->history(['Reclamo' => [$case->code]]),
        ], 'meta' => ['permissions' => ['manage' => $user->hasPermissionTo('crm.clients.manage')]]]);
    }

    public function start(Request $request, string $code, AfterSalesService $service): JsonResponse
    {
        $case = $this->findCase($code)->assertIfMatch($request);

        return response()->json(['data' => $this->present->case($service->start($case, $this->actor($request))->load('client'))]);
    }

    public function followUp(Request $request, string $code, AfterSalesService $service): JsonResponse
    {
        $case = $this->findCase($code);
        $data = $request->validate(['message' => 'required|string|max:5000'], ['message.required' => 'Escriba la nota de seguimiento']);
        $service->followUp($case, $data['message'], $this->actor($request));

        return response()->json(['data' => $this->present->case($case->refresh()->load('client', 'events'), withEvents: true)], 201);
    }

    public function resolve(Request $request, string $code, AfterSalesService $service): JsonResponse
    {
        $case = $this->findCase($code)->assertIfMatch($request);
        $data = $request->validate(['resolution' => 'required|string|max:5000'], ['resolution.required' => 'Describa la resolución del caso']);

        return response()->json(['data' => $this->present->case($service->resolve($case, $data['resolution'], $this->actor($request))->load('client'))]);
    }

    public function close(Request $request, string $code, AfterSalesService $service): JsonResponse
    {
        $case = $this->findCase($code)->assertIfMatch($request);

        return response()->json(['data' => $this->present->case($service->close($case, $this->actor($request))->load('client'))]);
    }

    /** CRM-BR-46. */
    public function segments(Request $request, LoyaltySegments $segments): JsonResponse
    {
        $user = $this->actor($request);
        $filters = $request->validate([
            'filter.segment' => 'nullable|string|max:40',
            'filter.recency' => ['nullable', Rule::in(LoyaltySegments::RECENCY)],
            'filter.destination' => 'nullable|string|max:120',
        ])['filter'] ?? [];
        $clients = $this->ownerFilter($this->access->clients(Client::query(), $user), $request, 'crm_clients.owner_id');

        return response()->json(['data' => $segments->rows($clients, $user, $filters)->map(fn ($row) => $this->present->client($row['client'], $row['stats']) + [
            'days_since_purchase' => $row['days_since_purchase'],
            'destinations' => $row['destinations'],
            'has_open_opportunity' => $row['has_open_opportunity'],
        ])->all()]);
    }

    /** CRM-BR-45: precarga de la oportunidad de recompra. */
    public function rebuyProposal(Request $request, string $code, LinkedData $linked, CrmClock $clock): JsonResponse
    {
        $client = Client::where('code', $code)->firstOrFail();
        $user = $this->actor($request);
        $this->access->ensureClient($client, $user);
        $ownerId = $this->access->ownOnly($user) ? $user->id : null;

        $stats = $linked->purchaseStats([$client->id], $ownerId)[$client->id] ?? [];
        $count = (int) ($stats['reservations_count'] ?? 0);
        $lastDestination = collect($stats['visited_destinations'] ?? [])->last()
            ?? Opportunity::where('client_id', $client->id)->where('stage', Opportunity::WON)->latest('closed_at')->value('destination_label');
        $suggestion = self::REBUY_DEFAULT;
        foreach (self::REBUY_MAP as $from => $to) {
            if ($lastDestination !== null && stripos($lastDestination, $from) !== false) {
                $suggestion = $to;
                break;
            }
        }
        $open = Opportunity::where('client_id', $client->id)->whereIn('stage', Opportunity::OPEN_STAGES)->get();

        return response()->json(['data' => [
            'proposal' => [
                'client_id' => $client->id,
                'title' => "Recompra — {$client->name} — {$suggestion}",
                'destination_label' => $suggestion,
                'amount' => $count > 0 ? round((float) $stats['total_pen'] / $count, 2) : 4000,
                'currency' => 'PEN',
                'stage' => 'calificacion',
                'expected_close_date' => $clock->today()->addDays(30)->toDateString(),
                'origin' => OpportunityService::ORIGIN_LOYALTY,
                'owner_id' => $ownerId ?? $client->owner_id,
            ],
            'open_opportunities' => $this->present->many($open, 'opportunity'),
            'requires_confirmation' => $open->isNotEmpty(),
        ]]);
    }

    private function findCase(string $code): AfterSalesCase
    {
        return AfterSalesCase::where('code', $code)->firstOrFail();
    }
}
