<?php

namespace App\Http\Controllers\Api\Crm;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Crm\AfterSalesCase;
use App\Services\AuditLogger;
use App\Services\Crm\ClaimBookPresenter;
use App\Services\Crm\ClaimBookService;
use App\Services\Crm\ClaimCertificate;
use App\Services\Reports\FileWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Spec 12 §6: libro de reclamaciones (gestión interna con `crm.claims.manage`; configuración editable con `admin.settings.manage`). */
class ClaimBookController extends Controller
{
    public function __construct(private readonly ClaimBookService $service, private readonly ClaimBookPresenter $present) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->present->index((array) $request->input('filter', []) + ['q' => $request->query('q')]) + ['meta' => [
            'sedes' => $this->service->sedes()->all(), 'statuses' => ClaimBookService::STATUS_LABELS,
        ]]);
    }

    /** Datos del formulario (registro presencial y vista previa interna del formulario del consumidor). */
    public function form(): JsonResponse
    {
        return response()->json(['data' => ['sedes' => $this->service->sedes()->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values()->all(),
            'doc_types' => ClaimBookService::DOC_TYPES, 'response_days' => $this->service->settings()->response_days, 'provider' => $this->service->provider()]]);
    }

    public function show(Request $request, string $claim): JsonResponse
    {
        return response()->json(['data' => $this->present->detail($this->find($claim), $request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $c = $this->service->register($request->all(), 'in_person', $request->user());

        return response()->json(['data' => ['code' => $c->code, 'book_number' => $c->book_number], 'message' => "Hoja {$c->book_number} registrada"], 201);
    }

    public function review(Request $request, string $claim): JsonResponse
    {
        $c = $this->find($claim);
        $c->assertIfMatch($request);
        $c = $this->service->review($c, $request->input('owner_id'), $request->user());

        return response()->json(['data' => ['code' => $c->code], 'message' => "Hoja {$c->book_number} en evaluación"]);
    }

    public function respond(Request $request, string $claim): JsonResponse
    {
        $c = $this->service->respond($this->find($claim), $request->all(), $request->user());
        $late = (bool) ($c->response['late'] ?? false);

        return response()->json(['data' => ['code' => $c->code, 'late' => $late],
            'message' => "Respuesta enviada a {$c->consumer['email']}".($late ? ' (fuera de plazo)' : '')]);
    }

    public function resend(Request $request, string $claim): JsonResponse
    {
        $c = $this->service->resend($this->find($claim), $request->user());

        return response()->json(['data' => ['code' => $c->code], 'message' => "Respuesta reenviada a {$c->consumer['email']}"]);
    }

    public function close(Request $request, string $claim): JsonResponse
    {
        $c = $this->find($claim);
        $c->assertIfMatch($request);
        $c = $this->service->close($c, $request->input('note'), $request->user());

        return response()->json(['data' => ['code' => $c->code], 'message' => "Hoja {$c->book_number} cerrada"]);
    }

    public function links(Request $request, string $claim): JsonResponse
    {
        $c = $this->service->link($this->find($claim), $request->only(['client_id', 'reservation_code']), $request->user());

        return response()->json(['data' => ['code' => $c->code], 'message' => 'Vínculos actualizados']);
    }

    public function certificate(Request $request, string $claim, ClaimCertificate $pdf, AuditLogger $audit): Response
    {
        $c = $this->find($claim);
        $audit->log('Libro de reclamaciones', $c->book_number, 'descargar_constancia', null, null, actor: $request->user());

        return response($pdf->pdf($c), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename=\"constancia-{$c->book_number}.pdf\""]);
    }

    public function reports(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present->reports($request->query('year') ? (int) $request->query('year') : null)
            + ['can_export' => $request->user()->hasPermissionTo('reports.export')]]);
    }

    /** Relación anual para INDECOPI (CSV/XLSX/PDF) con `reports.export`. */
    public function export(Request $request, AuditLogger $audit): Response
    {
        $year = (int) ($request->input('year') ?: $this->service->today()->format('Y'));
        $format = in_array($request->input('format'), ['CSV', 'XLSX', 'PDF'], true) ? $request->input('format') : 'CSV';
        $rows = $this->present->annual($year);
        $audit->log('Exportación', "LR-{$year}", 'relacion_anual', null, count($rows)." hoja(s) · {$format}", actor: $request->user());
        $ext = ['CSV' => 'csv', 'XLSX' => 'xlsx', 'PDF' => 'pdf'][$format];
        $type = ['CSV' => 'text/csv; charset=UTF-8', 'XLSX' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'PDF' => 'application/pdf'][$format];

        return response(FileWriter::write($format, "Libro de reclamaciones · relación anual {$year}", ClaimBookPresenter::ANNUAL_HEADER, $rows), 200,
            ['Content-Type' => $type, 'Content-Disposition' => "attachment; filename=\"libro-reclamaciones-{$year}.{$ext}\""]);
    }

    public function runAlerts(Request $request): JsonResponse
    {
        $summary = $this->service->deadlineAlerts($request->user());

        return response()->json(['data' => ['summary' => $summary], 'message' => $summary]);
    }

    public function settings(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present->settings($request->user())]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $this->service->settings()->assertIfMatch($request);
        $this->service->saveSettings($request->all(), $request->user());

        return response()->json(['data' => $this->present->settings($request->user()), 'message' => 'Configuración del libro guardada']);
    }

    private function find(string $key): AfterSalesCase
    {
        $c = AfterSalesCase::where('is_book', true)->where(fn ($q) => Str::isUuid($key) ? $q->whereKey($key) : $q->where('code', mb_strtoupper($key))->orWhere('book_number', mb_strtoupper($key)))->first();

        return $c ?? throw ApiException::notFound();
    }
}
