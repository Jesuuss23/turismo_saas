<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AuditQuery;
use App\Services\Reports\ExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Bitácora del tenant (PROC-063, spec 09 §6.7). */
class AuditController extends Controller
{
    public function __construct(private readonly AuditQuery $audit) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['period', 'entity', 'action', 'user', 'critical', 'q']);
        // Auditoría de cambios de permisos (spec 09 §6.3): `?entity=Permiso,Rol`.
        if (str_contains((string) ($filters['entity'] ?? ''), ',')) {
            $filters['entities'] = explode(',', $filters['entity']);
            unset($filters['entity']);
        }
        // Sin `admin.audit.view` (solo `admin.roles.manage`): únicamente la auditoría de permisos.
        if (! $request->user()->hasPermissionTo('admin.audit.view')) {
            $filters['entities'] = ['Permiso', 'Rol'];
            unset($filters['entity']);
        }

        return response()->json($this->audit->list($filters));
    }

    public function show(string $event): JsonResponse
    {
        return response()->json(['data' => $this->audit->show($event)]);
    }

    /** AUD-BR-03: exportar la bitácora es una exportación PROC-061. */
    public function export(Request $request, ExportService $exports): JsonResponse
    {
        $data = $request->validate(['format' => 'nullable|in:CSV,XLSX,PDF', 'filters' => 'nullable|array']);
        $export = $exports->request($request->user(), ['report_key' => 'auditoria', 'filters' => $data['filters'] ?? [], 'format' => $data['format'] ?? 'CSV', 'scope' => 'detailed'],
            $request->header('Idempotency-Key'));

        return response()->json(['data' => $exports->row($export), 'export_id' => $export->id, 'message' => "Exportación {$export->displayCode()} en proceso"], 202);
    }
}
