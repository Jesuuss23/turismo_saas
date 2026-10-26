<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Models\Catalog\Departure;
use App\Models\Catalog\Hold;
use App\Models\Catalog\Product;
use App\Services\Catalog\AvailabilityService;
use App\Services\Crm\CrmClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Spec 02 §6.3: salidas y holds globales (PROC-020). */
class DepartureController extends CatalogController
{
    public static function rules(Request $request, bool $editing = false): array
    {
        $req = $editing ? 'sometimes' : 'required';

        return $request->validate([
            'departure_date' => "{$req}|date_format:Y-m-d",
            'departure_time' => "{$req}|date_format:H:i",
            'capacity' => "{$req}|integer|min:1|max:".AvailabilityService::MAX_CAPACITY,
            'status' => 'sometimes|in:abierta,cerrada,cancelada',
            'provider_id' => 'sometimes|nullable|uuid',
            'guide_id' => 'sometimes|nullable|string|max:64',
            'vehicle_id' => 'sometimes|nullable|string|max:64',
            'meeting_point' => "{$req}|string|max:160",
            'return_point' => 'sometimes|nullable|string|max:160',
            'notes' => 'sometimes|nullable|string|max:2000',
        ]);
    }

    /** Programación, calendario (`month=YYYY-MM`) y KPIs. */
    public function index(Request $request, AvailabilityService $availability, CrmClock $clock): JsonResponse
    {
        $today = $clock->today()->toDateString();
        $query = Departure::query()->with(['product:id,ref,name,status', 'provider:id,name'])
            ->when($request->input('filter.product'), fn ($q, $p) => $q->where('product_id', $this->product($p)->id))
            ->when($request->input('filter.status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->input('filter.period') === 'future', fn ($q) => $q->whereDate('departure_date', '>=', $today))
            ->when($request->input('filter.period') === 'past', fn ($q) => $q->whereDate('departure_date', '<', $today));
        if ($month = $request->input('month')) {
            $start = Carbon::createFromFormat('Y-m-d', $month.'-01');
            $query->whereBetween('departure_date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()]);
        }
        $rows = $query->orderBy('departure_date')->orderBy('departure_time')->limit(1000)->get();
        $held = $availability->heldMany($rows->pluck('id'));

        $future = Departure::whereDate('departure_date', '>=', $today)->whereIn('status', ['abierta', 'completa'])->get();
        $futureHeld = $availability->heldMany($future->pluck('id'));

        return $this->ok($rows->map(fn ($d) => $this->present->departure($d, $held[$d->id] ?? 0, $availability->available($d, $held[$d->id] ?? 0)))->all(), [
            'kpis' => [
                'future' => $future->count(),
                'capacity' => $future->sum('capacity'),
                'sold' => $future->sum('sold'),
                'held' => array_sum($futureHeld),
                'available' => $future->sum(fn ($d) => $availability->available($d, $futureHeld[$d->id] ?? 0)),
            ],
            'pending_expired_holds' => $availability->pendingExpired(),
            'last_expiry_run' => $availability->lastExpiryRun(),
            'hold_minutes' => $availability->holdMinutes(),
        ]);
    }

    public function show(string $id, AvailabilityService $availability): JsonResponse
    {
        $departure = $this->find(Departure::with(['product:id,ref,name,status', 'provider:id,name']), $id);
        $held = $availability->held($departure);
        $holds = Hold::where('departure_id', $departure->id)->with('departure.product:id,ref,name')->orderByDesc('created_at')->get();
        $this->present->users->preloadUsers($holds->pluck('created_by'));

        return $this->ok($this->present->departure($departure, $held, $availability->available($departure, $held), [
            'holds' => $holds->map(fn ($h) => $this->present->hold($h))->all(),
        ]));
    }

    public function store(Request $request, AvailabilityService $availability): JsonResponse
    {
        $request->validate(['product_id' => 'required|uuid']);
        $product = $this->find(Product::query(), $request->input('product_id'));
        $result = $availability->createDeparture($product, self::rules($request), $this->actor($request), true);

        return $this->created(['id' => $result['departure']->id, 'ref' => $result['departure']->ref], $result['warnings']);
    }

    public function confirmSeats(Request $request, string $id, AvailabilityService $availability): JsonResponse
    {
        $departure = $this->find(Departure::query(), $id);
        if ($request->hasHeader('If-Match')) {
            $this->ifMatch($request, $departure);
        }
        $data = $request->validate(['quantity' => 'required|integer|min:1|max:60', 'reference' => 'required|string|max:60', 'notes' => 'nullable|string|max:1000']);
        $hold = $availability->confirmSeats($departure, $data, $this->actor($request));

        return $this->created($this->present->hold($hold->load('departure.product')));
    }

    public function holds(Request $request): JsonResponse
    {
        $rows = Hold::query()->with('departure.product:id,ref,name')
            ->when($request->input('filter.status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->input('filter.departure'), fn ($q, $d) => $q->where('departure_id', $d))
            ->orderByDesc('created_at')->limit(500)->get();
        $this->present->users->preloadUsers($rows->pluck('created_by'));

        return $this->ok($rows->map(fn ($h) => $this->present->hold($h))->all());
    }

    public function storeHold(Request $request, AvailabilityService $availability): JsonResponse
    {
        $data = $request->validate(['departure_id' => 'required|uuid', 'quantity' => 'required|integer|min:1|max:60', 'minutes' => 'nullable|integer|min:'.AvailabilityService::MIN_HOLD_MINUTES.'|max:10080',
            'reference' => 'required|string|max:60', 'notes' => 'nullable|string|max:1000']);
        $departure = $this->find(Departure::query(), $data['departure_id']);
        $hold = $availability->hold($departure, $data, $this->actor($request));

        return $this->created($this->present->hold($hold->load('departure.product')));
    }

    public function holdAction(Request $request, string $id, string $action, AvailabilityService $availability): JsonResponse
    {
        $hold = $this->find(Hold::with('departure'), $id);
        $user = $this->actor($request);
        $hold = match ($action) {
            'confirm' => $availability->confirm($hold, $user),
            'release' => $availability->release($hold, (string) $request->input('reason'), $user),
            'consume' => $availability->consume($hold, $user),
        };

        return $this->ok($this->present->hold($hold->load('departure.product')));
    }

    /** JOB-01 manual. */
    public function expire(Request $request, AvailabilityService $availability): JsonResponse
    {
        $refs = $availability->expireHolds($this->actor($request));

        return $this->ok(['expired' => $refs], ['message' => count($refs).' hold(s) expirado(s)']);
    }
}
