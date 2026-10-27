<?php

namespace App\Http\Controllers\Api\Ops;

use App\Exceptions\ApiException;
use App\Models\Bookings\BookedService;
use App\Models\Catalog\Departure;
use App\Models\Ops\Assignment;
use App\Models\Ops\Driver;
use App\Models\Ops\Guide;
use App\Models\Ops\Order;
use App\Models\Ops\Vehicle;
use App\Services\AuditLogger;
use App\Services\Crm\CrmClock;
use App\Services\Ops\AssignmentService;
use App\Services\Ops\OpsAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/** Spec 05 §6.3: asignaciones, recursos (guías, conductores, vehículos) y calendario operativo. */
class AssignmentController extends OpsController
{
    private const MODELS = ['guide' => Guide::class, 'driver' => Driver::class, 'vehicle' => Vehicle::class];

    /** Asignación rápida (`filter[incomplete]`, `filter[from]=today`) o agenda de un recurso (`filter[resource]=guide:<id>`). */
    public function index(Request $request, CrmClock $clock): JsonResponse
    {
        $from = $request->input('filter.from', 'today');
        $from = $from === 'today' ? $clock->today()->toDateString() : Carbon::parse($from)->toDateString();
        $query = Assignment::with(['order:id,code,status,reservation_id,coordinator_id', 'order.reservation:id,code,client_id', 'order.reservation.client:id,name', 'guide', 'driver', 'vehicle'])
            ->whereHas('order', fn ($q) => $q->whereNotIn('status', [Order::CANCELLED, Order::COMPLETED]))
            ->where('service_date', '>=', $from)
            ->when($request->boolean('filter.incomplete'), fn ($q) => $q->where('status', Assignment::PENDING))
            ->when($request->input('filter.resource'), function ($q, $resource) {
                [$type, $id] = array_pad(explode(':', $resource, 2), 2, null);
                abort_unless(isset(self::MODELS[$type]) && $id, 422);
                $q->where("{$type}_id", $id);
            })
            ->orderBy('service_date')->orderBy('service_time');

        return response()->json(['data' => $query->limit(300)->get()->map(fn (Assignment $a) => $this->present->assignment($a) + [
            'order' => ['id' => $a->order_id, 'code' => $a->order?->code, 'status' => $a->order?->status],
            'reservation' => $a->order?->reservation ? ['code' => $a->order->reservation->code, 'client' => $a->order->reservation->client?->name] : null,
        ])->all()]);
    }

    public function validateResources(Request $request, AssignmentService $service, string $assignment): JsonResponse
    {
        $a = Assignment::find($assignment) ?? throw ApiException::notFound();
        $data = $this->resourceData($request);

        return response()->json(['data' => $service->validate($a, $data['guide_id'] ?? null, $data['driver_id'] ?? null, $data['vehicle_id'] ?? null)]);
    }

    public function assign(Request $request, AssignmentService $service, string $assignment): JsonResponse
    {
        $a = (Assignment::find($assignment) ?? throw ApiException::notFound())->assertIfMatch($request);
        $data = $this->resourceData($request) + $request->validate(['ack_warnings' => 'boolean', 'notify' => 'nullable|array', 'notify.push' => 'boolean', 'notify.whatsapp' => 'boolean']);
        $a = $service->assign($a, $data, $this->actor($request));

        return $this->orderResponse($request, Order::findOrFail($a->order_id), meta: ['assignment' => $this->present->assignment($a->fresh(['guide', 'driver', 'vehicle']))]);
    }

    public function confirm(Request $request, AssignmentService $service, string $assignment): JsonResponse
    {
        $a = (Assignment::find($assignment) ?? throw ApiException::notFound())->assertIfMatch($request);
        $a = $service->confirm($a, $this->actor($request));

        return $this->orderResponse($request, Order::findOrFail($a->order_id));
    }

    public function release(Request $request, AssignmentService $service, string $assignment): JsonResponse
    {
        $a = (Assignment::find($assignment) ?? throw ApiException::notFound())->assertIfMatch($request);
        $a = $service->release($a, $this->actor($request));

        return $this->orderResponse($request, Order::findOrFail($a->order_id));
    }

    // ── Recursos ────────────────────────────────────────────────────────

    /** Recursos por tipo con su ocupación de 7 días y estado derivado (§12-7). */
    public function resources(Request $request, AssignmentService $service, CrmClock $clock): JsonResponse
    {
        $types = $request->input('filter.type') ? [$request->input('filter.type')] : array_keys(self::MODELS);
        $from = $clock->today()->toDateString();
        $to = $clock->today()->addDays(6)->toDateString();
        $out = [];
        foreach ($types as $type) {
            abort_unless(isset(self::MODELS[$type]), 422);
            foreach ($this->list($type) as $resource) {
                $out[] = $this->present->resourceCard($type, $resource, $service->occupations($type, $resource->id, $from, $to), $this->actor($request));
            }
        }

        return response()->json(['data' => $out, 'meta' => ['from' => $from, 'to' => $to,
            'can_assign' => $this->access->can($this->actor($request), OpsAccess::ASSIGN)]]);
    }

    /** Matriz recurso × día (`from`, `days`). */
    public function availability(Request $request, AssignmentService $service, CrmClock $clock): JsonResponse
    {
        $from = Carbon::parse($request->input('from', $clock->today()->toDateString()));
        $days = min(31, max(1, (int) $request->input('days', 7)));
        $to = $from->copy()->addDays($days - 1);
        $dates = collect(range(0, $days - 1))->map(fn ($i) => $from->copy()->addDays($i)->toDateString());
        $rows = [];
        foreach (array_keys(self::MODELS) as $type) {
            if ($request->input('filter.type') && $request->input('filter.type') !== $type) {
                continue;
            }
            foreach ($this->list($type) as $resource) {
                $occupations = $service->occupations($type, $resource->id, $from->toDateString(), $to->toDateString())->groupBy('date');
                $rows[] = [
                    'type' => $type, 'id' => $resource->id, 'code' => $resource->code, 'name' => $resource instanceof Vehicle ? $resource->label() : $resource->full_name,
                    'status' => $resource->status,
                    'days' => $dates->map(fn ($d) => ['date' => $d, 'items' => ($occupations[$d] ?? collect())->values()->all(),
                        'state' => $resource->status !== 'disponible' ? $resource->status : (isset($occupations[$d]) ? 'ocupado' : 'libre')])->all(),
                ];
            }
        }

        return response()->json(['data' => $rows, 'meta' => ['dates' => $dates->all()]]);
    }

    /** Licencias, carnés y SOAT vencidos o por vencer (≤ 30 días). */
    public function documentAlerts(AssignmentService $service): JsonResponse
    {
        return response()->json(['data' => $service->documentAlerts()]);
    }

    public function agenda(AssignmentService $service, CrmClock $clock, string $type, string $id): JsonResponse
    {
        $resource = $this->resource($type, $id);
        $from = $clock->today()->toDateString();

        return response()->json(['data' => [
            'resource' => $this->present->resourceCard($type, $resource, collect()),
            'occupations' => $service->occupations($type, $resource->id, $from, $clock->today()->addDays(60)->toDateString())->all(),
        ]]);
    }

    public function status(Request $request, AuditLogger $audit, AssignmentService $service, CrmClock $clock, string $type, string $id): JsonResponse
    {
        $resource = $this->resource($type, $id);
        $data = $request->validate(['status' => ['required', Rule::in($resource::STATUSES)]]);
        $before = $resource->status;
        $resource->forceFill(['status' => $data['status']])->save();
        $audit->log('Recurso', $resource->code, 'cambio_estado', $before, $data['status'], actor: $this->actor($request));
        $today = $clock->today();

        return response()->json(['data' => $this->present->resourceCard($type, $resource->fresh(),
            $service->occupations($type, $resource->id, $today->toDateString(), $today->copy()->addDays(6)->toDateString()))]);
    }

    // ── Calendario (§2) ─────────────────────────────────────────────────

    /** Servicios de órdenes activas y salidas programadas con flag `pending` (falta proveedor o recurso). */
    public function calendar(Request $request, CrmClock $clock): JsonResponse
    {
        $from = Carbon::parse($request->input('from', $clock->today()->startOfMonth()->toDateString()))->toDateString();
        $to = Carbon::parse($request->input('to', Carbon::parse($from)->addDays(41)->toDateString()))->toDateString();
        abort_if(Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 92, 422, 'Rango máximo de 92 días.');
        $kind = $request->input('filter.kind');

        $orders = Order::whereNotIn('status', [Order::CANCELLED])->where('date_from', '<=', $to)->where('date_to', '>=', $from)->get()->keyBy('reservation_id');
        $assignments = Assignment::whereIn('order_id', $orders->pluck('id'))->with(['guide', 'driver', 'vehicle'])->get()->keyBy('reservation_service_id');
        $services = BookedService::whereIn('reservation_id', $orders->keys())->whereBetween('service_date', [$from, $to])
            ->whereNotIn('status', [BookedService::CANCELLED, BookedService::REJECTED])->with('provider:id,name')
            ->when($kind && $kind !== 'salida', fn ($q) => $q->where('service_kind', $kind))
            ->orderBy('service_date')->orderBy('service_time')->get();
        $events = $kind === 'salida' ? collect() : $services->map(function (BookedService $s) use ($orders, $assignments) {
            $a = $assignments[$s->id] ?? null;
            $order = $orders[$s->reservation_id];
            $pending = in_array($s->status, [BookedService::PENDING, BookedService::REQUESTED], true) || ($s->requires_assignment && (! $a || $a->status === Assignment::PENDING));

            return [
                'type' => 'service', 'id' => $s->id, 'date' => $s->service_date->toDateString(), 'time' => $s->service_time ? substr((string) $s->service_time, 0, 5) : null,
                'kind' => $s->service_kind, 'title' => $s->name, 'provider' => $s->provider?->name, 'status' => $s->status,
                'status_label' => BookedService::STATUS_LABELS[$s->status] ?? $s->status, 'order' => ['id' => $order->id, 'code' => $order->code],
                'assignment_id' => $a?->id, 'resources' => $a ? array_values(array_filter([$a->guide?->full_name, $a->driver?->full_name, $a->vehicle?->plate])) : [],
                'pending' => $pending,
            ];
        });
        if (! $kind || in_array($kind, ['salida', 'tour'], true)) {
            $departures = Departure::whereBetween('departure_date', [$from, $to])->where('status', '<>', 'cancelada')->with('product:id,ref,name')->get();
            $guides = Guide::whereIn('id', $departures->pluck('guide_id')->filter())->get()->keyBy('id');
            $vehicles = Vehicle::whereIn('id', $departures->pluck('vehicle_id')->filter())->get()->keyBy('id');
            $events = $events->concat($departures->map(fn (Departure $d) => [
                'type' => 'departure', 'id' => $d->id, 'date' => $d->departure_date->toDateString(), 'time' => substr((string) $d->departure_time, 0, 5),
                'kind' => 'salida', 'title' => trim("{$d->ref} · ".($d->product?->name ?? '')), 'provider' => null, 'status' => $d->status, 'status_label' => ucfirst((string) $d->status),
                'order' => null, 'assignment_id' => null,
                'resources' => array_values(array_filter([$guides[$d->guide_id]->full_name ?? null, $vehicles[$d->vehicle_id]->plate ?? null])),
                'pending' => ! $d->guide_id || ! $d->vehicle_id,
            ]));
        }

        return response()->json(['data' => $events->sortBy(fn ($e) => $e['date'].($e['time'] ?? '99'))->values()->all(), 'meta' => ['from' => $from, 'to' => $to,
            'can_assign' => $this->access->can($this->actor($request), OpsAccess::ASSIGN)]]);
    }

    private function resourceData(Request $request): array
    {
        return $request->validate(['guide_id' => 'nullable|uuid', 'driver_id' => 'nullable|uuid', 'vehicle_id' => 'nullable|uuid']);
    }

    private function list(string $type): Collection
    {
        return self::MODELS[$type]::orderBy('code')->get();
    }

    private function resource(string $type, string $id): Model
    {
        abort_unless(isset(self::MODELS[$type]), 404);

        return $this->findBy(self::MODELS[$type], $id);
    }
}
