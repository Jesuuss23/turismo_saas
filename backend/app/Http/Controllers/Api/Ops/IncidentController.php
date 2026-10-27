<?php

namespace App\Http\Controllers\Api\Ops;

use App\Exceptions\ApiException;
use App\Models\Bookings\BookedService;
use App\Models\Iam\User;
use App\Models\Ops\Evidence;
use App\Models\Ops\Incident;
use App\Models\Ops\Order;
use App\Services\Ops\EvidenceService;
use App\Services\Ops\IncidentService;
use App\Services\Ops\OpsAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Spec 05 §6.6: incidencias (PROC-037) con SLA y bitácora, y evidencias (PROC-038). */
class IncidentController extends OpsController
{
    public function index(Request $request): JsonResponse
    {
        $incidents = Incident::with('order:id,code')
            ->when($request->input('filter.status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->input('filter.severity'), fn ($q, $s) => $q->where('severity', $s))
            ->when($request->input('filter.type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->input('filter.order'), fn ($q, $o) => $q->whereHas('order', fn ($q) => $q->where('code', $o)))
            ->orderByRaw("CASE status WHEN 'abierta' THEN 0 WHEN 'en_proceso' THEN 1 WHEN 'resuelta' THEN 2 ELSE 3 END")
            ->orderByRaw("CASE severity WHEN 'critica' THEN 0 WHEN 'alta' THEN 1 WHEN 'media' THEN 2 ELSE 3 END")->orderByDesc('created_at')
            ->limit(500)->get();
        $this->present->users->preloadUsers($incidents->pluck('assignee_id')->concat($incidents->pluck('reported_by')));
        $active = Incident::whereIn('status', Incident::ACTIVE);

        return response()->json(['data' => $incidents->map(fn (Incident $i) => $this->present->incidentRow($i))->all(), 'meta' => [
            'kpis' => [
                'open' => (clone $active)->count(), 'critical' => (clone $active)->where('severity', 'critica')->count(),
                'sla_breached' => (clone $active)->where('sla_due_at', '<', now())->count(),
                'resolved' => Incident::where('status', Incident::RESOLVED)->count(),
            ],
            'types' => Incident::TYPES, 'severities' => Incident::SEVERITY_LABELS, 'sla_hours' => Incident::SLA_HOURS, 'statuses' => Incident::STATUS_LABELS,
            'assignees' => User::role([...OpsAccess::COORDINATOR_ROLES])->where('status', User::ACTIVE)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->all(),
            'orders' => Order::whereNotIn('status', [Order::CANCELLED, Order::COMPLETED])->orderBy('date_from')->get(['id', 'code', 'title'])
                ->map(fn ($o) => ['id' => $o->id, 'code' => $o->code, 'title' => $o->title])->all(),
            'can_manage' => $this->access->can($this->actor($request), OpsAccess::INCIDENTS),
        ]]);
    }

    public function store(Request $request, IncidentService $incidents, EvidenceService $evidences): JsonResponse
    {
        $data = $request->validate([
            'order_id' => 'nullable|string', 'reservation_service_id' => 'nullable|uuid', 'type' => ['required', Rule::in(Incident::TYPES)],
            'severity' => ['required', Rule::in(array_keys(Incident::SLA_HOURS))], 'assignee_id' => 'nullable|uuid',
            'title' => 'required|string|max:200', 'description' => 'required|string|max:5000',
            'evidences' => 'nullable|array|max:5', ...$this->uploadRules('evidences.*.'),
        ], ['title.required' => 'Indique el título (OPS-BR-64).', 'description.required' => 'Describa la incidencia (OPS-BR-64).']);
        if (! empty($data['order_id'])) {
            $data['order_id'] = $this->order($data['order_id'])->id;
        }
        $actor = $this->actor($request);
        $incident = DB::transaction(function () use ($data, $actor, $incidents, $evidences) {
            $incident = $incidents->create($data, $actor);
            foreach ($data['evidences'] ?? [] as $upload) {
                $evidences->store($upload, null, null, $incident, $actor);
            }

            return $incident;
        });

        return $this->detail($request, $incident, 201);
    }

    public function show(Request $request, string $incident): JsonResponse
    {
        return $this->detail($request, $this->findBy(Incident::class, $incident));
    }

    public function action(Request $request, IncidentService $incidents, string $incident, string $action): JsonResponse
    {
        $i = $this->findBy(Incident::class, $incident);
        $actor = $this->actor($request);
        if ($action === 'comments') {
            $data = $request->validate(['message' => 'required|string|max:2000', 'visibility' => ['nullable', Rule::in(['interna', 'proveedor'])]]);
            $incidents->comment($i, $data['message'], $actor, $data['visibility'] ?? 'interna');

            return $this->detail($request, $i, 201);
        }
        $i->assertIfMatch($request);
        $i = match ($action) {
            'take' => $incidents->take($i, $actor),
            'assignee' => $incidents->assign($i, $request->validate(['assignee_id' => 'required|uuid'])['assignee_id'], $actor),
            'classification' => (function () use ($request, $incidents, $i, $actor) {
                $data = $request->validate(['severity' => ['required', Rule::in(array_keys(Incident::SLA_HOURS))], 'type' => ['required', Rule::in(Incident::TYPES)]]);

                return $incidents->reclassify($i, $data['severity'], $data['type'], $actor);
            })(),
            'resolve' => $incidents->resolve($i, (string) ($request->validate(['resolution' => 'nullable|string|max:5000'])['resolution'] ?? ''), $actor),
            'close' => $incidents->close($i, $request->validate(['closing_comment' => 'nullable|string|max:5000'])['closing_comment'] ?? null, $actor),
            'reopen' => $incidents->reopen($i, (string) ($request->validate(['reason' => 'nullable|string|max:500'])['reason'] ?? ''), $actor),
        };

        return $this->detail($request, $i);
    }

    // ── Evidencias ──────────────────────────────────────────────────────

    public function storeIncidentEvidences(Request $request, EvidenceService $evidences, string $incident): JsonResponse
    {
        $i = $this->findBy(Incident::class, $incident);
        $data = $request->validate(['uploads' => 'required|array|min:1|max:5', ...$this->uploadRules('uploads.*.')]);
        DB::transaction(function () use ($data, $evidences, $i, $request) {
            foreach ($data['uploads'] as $upload) {
                $evidences->store($upload, null, null, $i, $this->actor($request));
            }
        });

        return $this->detail($request, $i, 201);
    }

    public function storeOrderEvidences(Request $request, EvidenceService $evidences, string $order): JsonResponse
    {
        $o = $this->order($order);
        $data = $request->validate(['reservation_service_id' => 'nullable|uuid', 'uploads' => 'required|array|min:1|max:5', ...$this->uploadRules('uploads.*.')]);
        $service = ! empty($data['reservation_service_id']) ? (BookedService::find($data['reservation_service_id']) ?? throw ApiException::invalid('reservation_service_id', 'Servicio no encontrado.')) : null;
        $duplicates = 0;
        DB::transaction(function () use ($data, $evidences, $o, $service, $request, &$duplicates) {
            foreach ($data['uploads'] as $upload) {
                [, $duplicate] = $evidences->store($upload, $o, $service, null, $this->actor($request));
                $duplicates += (int) $duplicate;
            }
        });

        return $this->orderResponse($request, $o, 201, ['duplicates' => $duplicates]);
    }

    public function destroyEvidence(Request $request, EvidenceService $evidences, string $evidence): JsonResponse
    {
        $e = Evidence::find($evidence) ?? throw ApiException::notFound();
        $data = $request->validate(['reason' => 'nullable|string|max:300']);
        $evidences->remove($e, (string) ($data['reason'] ?? ''), $this->actor($request));

        return response()->json(['data' => null]);
    }

    /** Archivo de la evidencia (vista previa en la orden / incidencia). */
    public function file(EvidenceService $evidences, string $evidence): Response
    {
        $e = Evidence::find($evidence) ?? throw ApiException::notFound();
        $content = $evidences->content($e) ?? throw ApiException::notFound();

        return response($content, 200, ['Content-Type' => $e->mime_type, 'Content-Disposition' => 'inline; filename="'.addslashes($e->file_name).'"',
            'Cache-Control' => 'private, max-age=300']);
    }

    private function uploadRules(string $prefix): array
    {
        return [
            "{$prefix}file_name" => 'required|string|max:200', "{$prefix}mime_type" => 'required|string|max:100',
            "{$prefix}content_base64" => 'required|string', "{$prefix}sha256" => 'nullable|string|size:64', "{$prefix}captured_at" => 'nullable|date',
        ];
    }

    private function detail(Request $request, Incident $incident, int $status = 200): JsonResponse
    {
        $incident = Incident::with(['order:id,code', 'reservation', 'service'])->findOrFail($incident->id);

        return response()->json(['data' => $this->present->incidentDetail($incident, $this->actor($request))], $status);
    }
}
