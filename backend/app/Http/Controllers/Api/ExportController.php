<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Crm\CrmExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /exports (spec 01 §6.6, SEC-BR-23). Respeta el alcance del rol y audita `exportar`.
 * Por ahora genera el CSV en línea (volúmenes de CRM); la cola `reports` + S3 llega con Reportes.
 */
class ExportController extends Controller
{
    public function __invoke(Request $request, CrmExporter $exporter, AuditLogger $audit): Response
    {
        $data = $request->validate([
            'resource' => 'required|string|in:'.implode(',', array_keys(CrmExporter::RESOURCES)),
            'filters' => 'nullable|array',
        ]);
        /** @var User $user */
        $user = $request->user();
        $required = CrmExporter::RESOURCES[$data['resource']];
        if (! collect($required)->contains(fn ($p) => $user->hasPermissionTo($p))) {
            $audit->isolated('Exportación', $data['resource'], 'exportar_denegado', $user->roleName(), 'requiere '.implode(' | ', $required));
            throw ApiException::forbidden('SEC-BR-23', 'No tiene permiso para exportar este recurso', $required);
        }

        [$header, $rows] = $exporter->rows($data['resource'], $user, $data['filters'] ?? []);
        $audit->log('Exportación', $data['resource'], 'exportar', null, count($rows).' filas');
        $filename = Str::slug(str_replace('.', ' ', $data['resource'])).'-'.now()->format('Ymd-His').'.csv';

        return response(CrmExporter::toCsv($header, $rows), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'X-Export-Rows' => (string) count($rows),
        ]);
    }
}
