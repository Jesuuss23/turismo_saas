<?php

namespace App\Http\Controllers\Api\Ops;

use App\Exceptions\ApiException;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\Reservation;
use App\Models\Iam\User;
use App\Models\Ops\Assignment;
use App\Models\Ops\Incident;
use App\Models\Ops\Order;
use App\Models\Ops\OrderTask;
use App\Models\Ops\SupplierRequest;
use App\Services\Crm\CrmClock;
use App\Services\Ops\OpsAccess;
use App\Services\Ops\ServiceOps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Spec 05 §6.1–6.2: órdenes operativas, transiciones, cierre, tareas y servicios con proveedor. */
class OrderController extends OpsController
{
    public function index(Request $request): JsonResponse
    {
        $query = Order::with(['reservation.client:id,name', 'assignments:id,order_id,status'])
            ->when($request->input('filter.status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->input('filter.coordinator'), fn ($q, $c) => $q->where('coordinator_id', $c === 'me' ? $this->actor($request)->id : $c))
            ->when(trim((string) $request->input('q')), function ($q, $term) {
                $like = '%'.mb_strtolower($term).'%';
                $q->where(fn ($q) => $q->whereRaw('lower(code) like ?', [$like])->orWhereRaw('lower(title) like ?', [$like])
                    ->orWhereHas('reservation', fn ($r) => $r->whereRaw('lower(code) like ?', [$like])->orWhereHas('client', fn ($c) => $c->whereRaw('lower(name) like ?', [$like]))));
            });
        $sort = $request->input('sort', 'date_from');
        $query->orderBy(ltrim($sort, '-') === 'code' ? 'code' : 'date_from', str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('code');
        $orders = $query->limit(500)->get();
        $this->present->users->preloadUsers($orders->pluck('coordinator_id'));

        return response()->json(['data' => $orders->map(fn (Order $o) => $this->present->orderRow($o))->all(), 'meta' => [
            'statuses' => Order::STATUS_LABELS, 'can_create' => $this->access->can($this->actor($request), OpsAccess::ASSIGN),
        ]]);
    }

    /** KPIs (§2): en ejecución, servicios sin asignar, servicios hoy/mañana, incidencias abiertas/críticas. */
    public function kpis(CrmClock $clock): JsonResponse
    {
        $today = $clock->today()->toDateString();
        $tomorrow = $clock->today()->addDay()->toDateString();
        $active = fn ($q) => $q->whereNotIn('status', [Order::CANCELLED, Order::COMPLETED]);

        return response()->json(['data' => [
            'in_execution' => Order::where('status', Order::IN_EXECUTION)->count(),
            'active' => Order::where($active)->count(),
            'pending_assignment' => Assignment::where('status', Assignment::PENDING)->whereHas('order', $active)->count(),
            'services_today' => Assignment::where('service_date', $today)->whereHas('order', $active)->count(),
            'services_tomorrow' => Assignment::where('service_date', $tomorrow)->whereHas('order', $active)->count(),
            'incidents_open' => Incident::whereIn('status', Incident::ACTIVE)->count(),
            'incidents_critical' => Incident::whereIn('status', Incident::ACTIVE)->where('severity', 'critica')->count(),
        ]]);
    }

    public function candidates(): JsonResponse
    {
        $coordinators = User::role(OpsAccess::COORDINATOR_ROLES)->where('status', User::ACTIVE)->orderBy('name')->get(['id', 'name']);

        return response()->json(['data' => $this->orders->candidates()->values()->all(), 'meta' => [
            'coordinators' => $coordinators->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->all(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['reservation_id' => 'required|string', 'coordinator_id' => 'nullable|uuid']);
        $reservation = Reservation::where(fn ($q) => Str::isUuid($data['reservation_id']) ? $q->whereKey($data['reservation_id']) : $q->where('code', $data['reservation_id']))->first()
            ?? throw ApiException::invalid('reservation_id', 'Seleccione una reserva válida.');
        $order = $this->orders->create($reservation, $data['coordinator_id'] ?? null, $this->actor($request));

        return $this->orderResponse($request, $order, 201);
    }

    public function show(Request $request, string $order): JsonResponse
    {
        return $this->orderResponse($request, $this->order($order));
    }

    public function coordinator(Request $request, string $order): JsonResponse
    {
        $o = $this->order($order)->assertIfMatch($request);
        $data = $request->validate(['coordinator_id' => 'required|uuid']);

        return $this->orderResponse($request, $this->orders->setCoordinator($o, $data['coordinator_id'], $this->actor($request)));
    }

    /** `to-assignment`, `mark-assigned`, `confirm`, `start` (§4.1). */
    public function transition(Request $request, string $order, string $action): JsonResponse
    {
        $o = $this->order($order)->assertIfMatch($request);
        $actor = $this->actor($request);
        $o = match ($action) {
            'to-assignment' => $this->orders->toAssignment($o, $actor),
            'mark-assigned' => $this->orders->markAssigned($o, $actor),
            'confirm' => $this->orders->confirm($o, $actor),
            'start' => $this->orders->start($o, $actor),
        };

        return $this->orderResponse($request, $o);
    }

    public function closeChecks(string $order): JsonResponse
    {
        return response()->json(['data' => $this->orders->closeChecks($this->order($order))]);
    }

    public function close(Request $request, string $order): JsonResponse
    {
        $o = $this->order($order)->assertIfMatch($request);

        return $this->orderResponse($request, $this->orders->close($o, $this->actor($request)));
    }

    public function costSummary(Request $request, string $order): JsonResponse
    {
        return response()->json(['data' => $this->orders->costSummary($this->order($order), $this->access->seeMargin($this->actor($request)))]);
    }

    // ── Tareas ──────────────────────────────────────────────────────────

    public function storeTask(Request $request, string $order): JsonResponse
    {
        $data = $request->validate(['title' => 'required|string|max:200']);
        $this->orders->addTask($o = $this->order($order), $data['title'], $this->actor($request));

        return $this->orderResponse($request, $o, 201);
    }

    public function updateTask(Request $request, string $task): JsonResponse
    {
        $t = OrderTask::find($task) ?? throw ApiException::notFound();
        $data = $request->validate(['done' => 'required|boolean']);
        $this->orders->toggleTask($t, $data['done'], $this->actor($request));

        return $this->orderResponse($request, Order::findOrFail($t->order_id));
    }

    public function destroyTask(Request $request, string $task): JsonResponse
    {
        $t = OrderTask::find($task) ?? throw ApiException::notFound();
        $this->orders->deleteTask($t, $this->actor($request));

        return $this->orderResponse($request, Order::findOrFail($t->order_id));
    }

    // ── Servicios y proveedor (§6.2) ────────────────────────────────────

    public function serviceAction(Request $request, ServiceOps $ops, string $service, string $action): JsonResponse
    {
        $s = $this->service($service);
        $order = Order::where('reservation_id', $s->reservation_id)->first() ?? throw ApiException::notFound();
        $this->orders->assertActive($order);
        $actor = $this->actor($request);
        match ($action) {
            'supplier-request' => $ops->request($s, $actor),
            'supplier-confirmation' => $ops->confirm($s, $request->validate([
                'confirmation_code' => 'nullable|string|max:60', 'note' => 'nullable|string|max:500', 'time' => 'nullable|date_format:H:i',
            ]), $actor),
            'supplier-rejection' => $ops->reject($s, $request->validate(['reason' => 'required|string|max:300'], ['reason.required' => 'Indique el motivo del rechazo (OPS-BR-12).'])['reason'], $actor),
            'complete' => $ops->complete($s, $actor),
        };

        return $this->orderResponse($request, $order->fresh());
    }

    public function ensureAssignment(Request $request, string $order): JsonResponse
    {
        $o = $this->order($order);
        $data = $request->validate(['reservation_service_id' => 'required|uuid']);
        $service = BookedService::find($data['reservation_service_id']) ?? throw ApiException::invalid('reservation_service_id', 'Servicio no encontrado.');
        $this->orders->ensureAssignment($o, $service, $this->actor($request));

        return $this->orderResponse($request, $o, 201);
    }

    /** Solicitudes y respuestas de proveedor (incluye alternativas de la extranet). */
    public function supplierRequests(Request $request): JsonResponse
    {
        $rows = SupplierRequest::with(['service:id,code,name,reservation_id,service_date,status', 'service.reservation:id,code', 'provider:id,name'])
            ->when($request->input('filter.status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('requested_at')->limit(300)->get();

        return response()->json(['data' => $rows->map(fn (SupplierRequest $r) => [
            'id' => $r->id, 'status' => $r->status, 'channel' => $r->channel, 'provider' => $r->provider?->name,
            'service' => $r->service ? ['code' => $r->service->code, 'name' => $r->service->name, 'date' => $r->service->service_date->toDateString(),
                'status' => $r->service->status, 'reservation' => $r->service->reservation?->code] : null,
            'requested_at' => $r->requested_at->toIso8601String(), 'responded_at' => $r->responded_at?->toIso8601String(),
            'confirmation_code' => $r->confirmation_code, 'rejection_reason' => $r->rejection_reason, 'source' => $r->response_source, 'note' => $r->note,
        ])->all()]);
    }
}
