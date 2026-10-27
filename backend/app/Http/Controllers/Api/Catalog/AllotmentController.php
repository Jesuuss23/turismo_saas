<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Admin\ScheduledJob;
use App\Models\Catalog\Provider;
use App\Models\Catalog\SupplierService;
use App\Models\Inventory\Allotment;
use App\Models\Inventory\AllotmentCharge;
use App\Models\Inventory\AllotmentDay;
use App\Models\Inventory\AllotmentRequest;
use App\Services\Admin\IntegrationAdmin;
use App\Services\Inventory\AllotmentPresenter;
use App\Services\Inventory\AllotmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Spec 11 §6: cupos contratados (allotments). Lectura con `catalog.allotments.manage` o `view_profitability`; acciones con manage. */
class AllotmentController extends Controller
{
    public function __construct(
        private readonly AllotmentService $service,
        private readonly AllotmentPresenter $present,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->present->index(['status' => $request->input('filter.status'), 'kind' => $request->input('filter.kind'), 'all' => $request->boolean('all')]);
        $result['meta']['can_manage'] = $this->present->canManage($request->user());

        return response()->json($result);
    }

    /** Proveedores y servicios elegibles para el modal de contrato (Alojamiento, Tren, Entrada activos). */
    public function options(): JsonResponse
    {
        $services = SupplierService::with('provider:id,name,ref,status,type')->whereIn('type', array_keys(Allotment::SERVICE_TYPES))->where('status', 'activo')->orderBy('name')->get()
            ->filter(fn (SupplierService $s) => $s->provider?->status === 'activo');

        return response()->json(['data' => [
            'providers' => $services->pluck('provider')->unique('id')->sortBy('name')->values()->map(fn (Provider $p) => ['id' => $p->id, 'name' => $p->name, 'ref' => $p->ref, 'type' => $p->type])->all(),
            'services' => $services->values()->map(fn (SupplierService $s) => ['id' => $s->id, 'provider_id' => $s->provider_id, 'name' => $s->name, 'ref' => $s->ref,
                'type' => $s->type, 'kind' => Allotment::SERVICE_TYPES[$s->type], 'cost' => $s->cost, 'currency' => $s->currency])->all(),
            'kinds' => Allotment::KINDS, 'units' => Allotment::UNITS,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $a = $this->service->create($request->all(), $request->user());

        return response()->json(['data' => $this->present->detail($a), 'message' => "Contrato {$a->code} registrado"], 201);
    }

    public function show(Request $request, string $allotment): JsonResponse
    {
        return response()->json(['data' => $this->present->detail($this->find($allotment), $request->query('from')) + ['can_manage' => $this->present->canManage($request->user())]]);
    }

    public function update(Request $request, string $allotment): JsonResponse
    {
        $a = $this->find($allotment);
        $a->assertIfMatch($request);
        $a = $this->service->update($a, $request->only(['name', 'contract_ref', 'service_id', 'provider_id', 'valid_from', 'valid_to', 'base_qty', 'release_days',
            'net_rate', 'currency', 'penalty_pct', 'notes']), $request->user());

        return response()->json(['data' => $this->present->detail($a), 'message' => "Contrato {$a->code} actualizado"]);
    }

    public function release(Request $request, string $allotment): JsonResponse
    {
        $a = $this->service->releaseContract($this->find($allotment), (string) $request->input('reason'), $request->user());

        return response()->json(['data' => $this->present->detail($a), 'message' => "Contrato {$a->code} liberado"]);
    }

    public function grid(Request $request): JsonResponse
    {
        $result = $this->present->grid($request->query('from'), (int) $request->query('days', 14), $request->query('kind'), $request->boolean('include_closed'));
        $result['meta']['can_manage'] = $this->present->canManage($request->user());

        return response()->json($result);
    }

    public function day(Request $request, string $allotment, string $date): JsonResponse
    {
        $a = $this->find($allotment);

        return response()->json(['data' => $this->present->day($a, $this->service->day($a, $date)) + ['can_manage' => $this->present->canManage($request->user())]]);
    }

    public function releaseDay(Request $request, string $allotment, string $date): JsonResponse
    {
        $a = $this->find($allotment);
        $day = $this->service->day($a, $date);
        $header = $request->header('If-Match');
        $qty = $request->input('qty');
        if (! is_numeric($qty)) {
            throw ApiException::invalid('qty', 'Indique cuántas unidades libera.');
        }
        $day = $this->service->releaseDay($day, (int) $qty, $request->user(), $header === null || $header === '' ? null : (int) trim($header, '"W/'));

        return response()->json(['data' => $this->present->day($a->fresh(), $day->setRelation('allotment', $a->fresh())), 'message' => "Liberadas {$qty} unidades del {$day->service_date->format('d/m')}"]);
    }

    public function retain(Request $request, string $allotment, string $date): JsonResponse
    {
        $a = $this->find($allotment);
        $day = $this->service->retain($this->service->day($a, $date), (string) $request->input('reason'), $request->user());

        return response()->json(['data' => $this->present->day($a->fresh(), $day->setRelation('allotment', $a->fresh())), 'message' => 'Fecha retenida']);
    }

    public function unretain(Request $request, string $allotment, string $date): JsonResponse
    {
        $a = $this->find($allotment);
        $day = $this->service->unretain($this->service->day($a, $date), $request->user());

        return response()->json(['data' => $this->present->day($a->fresh(), $day->setRelation('allotment', $a->fresh())), 'message' => 'Retención quitada']);
    }

    public function requests(string $allotment): JsonResponse
    {
        $a = $this->find($allotment);

        return response()->json(['data' => AllotmentRequest::with(['responder:id,name', 'requester:id,name'])->where('allotment_id', $a->id)->orderByDesc('created_at')->get()
            ->map(fn ($r) => $this->present->request($r))->all()]);
    }

    public function storeRequest(Request $request, string $allotment): JsonResponse
    {
        $r = $this->service->requestExpansion($this->find($allotment), $request->all(), $request->user());

        return response()->json(['data' => $this->present->request($r->fresh()), 'message' => "Ampliación {$r->code} enviada al proveedor"], 201);
    }

    public function respond(Request $request, string $allotment, string $requestId): JsonResponse
    {
        $a = $this->find($allotment);
        $r = Str::isUuid($requestId) ? AllotmentRequest::where('allotment_id', $a->id)->find($requestId) : AllotmentRequest::where('allotment_id', $a->id)->where('code', $requestId)->first();
        $r ?? throw ApiException::notFound();
        if (! $request->has('accepted')) {
            throw ApiException::invalid('accepted', 'Indique si el proveedor aceptó.');
        }
        $r = $this->service->respond($r, $request->boolean('accepted'), (string) $request->input('response'), $request->user());

        return response()->json(['data' => $this->present->request($r), 'message' => "Ampliación {$r->code} ".($r->status === 'aceptada' ? 'aceptada' : 'rechazada')]);
    }

    public function charges(string $allotment): JsonResponse
    {
        $a = $this->find($allotment);

        return response()->json(['data' => AllotmentCharge::with(['day:id,service_date', 'payable:id,code,status'])
            ->whereIn('allotment_day_id', AllotmentDay::where('allotment_id', $a->id)->select('id'))->get()
            ->map(fn ($c) => ['date' => $c->day?->service_date?->toDateString(), 'qty' => $c->qty, 'amount' => $c->amount, 'currency' => $c->currency, 'fx_rate' => $c->fx_rate,
                'reason' => $c->reason, 'payable' => $c->payable?->code, 'payable_status' => $c->payable?->status])->sortByDesc('date')->values()->all()]);
    }

    public function pickup(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present->pickup(max(7, min(90, (int) $request->query('days', 30))), max(2, min(16, (int) $request->query('weeks', 8))))]);
    }

    public function releaseBoard(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present->releaseBoard(), 'meta' => ['can_manage' => $this->present->canManage($request->user()), 'kpis' => $this->present->kpis()]]);
    }

    /** POST /jobs/release: ejecuta JOB-10 ahora (registra `sys_job_runs`; un solo run a la vez). */
    public function runJob(Request $request, IntegrationAdmin $jobs): JsonResponse
    {
        $job = ScheduledJob::where('code', 'JOB-10')->firstOrFail();
        $row = $jobs->run($job, $request->user());

        return response()->json(['data' => $row, 'message' => $row['message']]);
    }

    private function find(string $key): Allotment
    {
        return (Str::isUuid($key) ? Allotment::find($key) : Allotment::where('code', mb_strtoupper($key))->first()) ?? throw ApiException::notFound();
    }
}
