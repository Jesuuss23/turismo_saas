<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reports\ExecutiveDashboard;
use App\Services\Reports\ExportService;
use App\Services\Reports\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Centro de reportes, exportaciones PROC-061 y panel ejecutivo (spec 09 §6.7). */
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports, private readonly ExportService $exports) {}

    public function show(Request $request, string $report): JsonResponse
    {
        return response()->json($this->reports->build($report, $request->user(), $request->only(['period', 'sede'])));
    }

    public function export(Request $request): JsonResponse
    {
        $data = $request->validate([
            'report_key' => ['required', Rule::in([...array_keys(ReportService::REPORTS), 'auditoria'])], 'filters' => 'nullable|array',
            'format' => ['required', Rule::in(ExportService::FORMATS)], 'scope' => 'nullable|in:filtered,detailed',
        ]);
        $export = $this->exports->request($request->user(), $data, $request->header('Idempotency-Key'));

        return response()->json(['data' => $this->exports->row($export), 'export_id' => $export->id, 'message' => "Exportación {$export->displayCode()} en proceso"], 202);
    }

    public function history(Request $request): JsonResponse
    {
        return response()->json($this->exports->history($request->user()));
    }

    public function download(Request $request, string $export): JsonResponse
    {
        $url = $this->exports->download($request->user(), $export);

        return response()->json(['url' => $url], 302, ['Location' => $url]);
    }

    /** Descarga pública por URL firmada (middleware `signed`). */
    public function file(Request $request, string $export): Response
    {
        [$bytes, $model] = $this->exports->file((string) $request->query('tenant'), $export);
        $type = ['CSV' => 'text/csv; charset=UTF-8', 'XLSX' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'PDF' => 'application/pdf'][$model->format];

        return response($bytes, 200, ['Content-Type' => $type, 'Content-Disposition' => 'attachment; filename="'.$model->displayCode().'.'.strtolower($model->format).'"']);
    }

    public function schedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'report_key' => ['required', Rule::in(array_keys(ReportService::REPORTS))], 'frequency' => ['required', Rule::in(array_keys(ExportService::FREQUENCIES))],
            'time' => ['required', 'date_format:H:i'], 'format' => ['required', Rule::in(ExportService::FORMATS)], 'recipients' => 'required|string|max:1000',
        ], ['recipients.required' => 'Indique al menos un destinatario', 'time.date_format' => 'Hora inválida (HH:MM)']);
        $job = $this->exports->schedule($request->user(), $data);

        return response()->json(['data' => ['id' => $job->id, 'code' => $job->code, 'name' => $job->name, 'cron_label' => $job->cron_label,
            'next_run_at' => $job->next_run_at?->toIso8601String()], 'message' => "Envío programado {$job->code}: {$job->cron_label}"], 201);
    }

    public function executive(Request $request, ExecutiveDashboard $dashboard): JsonResponse
    {
        return response()->json(['data' => $dashboard->executive($request->user())]);
    }

    public function manager(Request $request, ExecutiveDashboard $dashboard): JsonResponse
    {
        return response()->json(['data' => $dashboard->manager($request->user())]);
    }
}
