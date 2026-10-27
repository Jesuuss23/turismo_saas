<?php

namespace App\Services\Reports;

use App\Exceptions\ApiException;
use App\Jobs\GenerateExport;
use App\Models\Admin\ReportExport;
use App\Models\Admin\ScheduledJob;
use App\Models\Iam\User;
use App\Services\Admin\AuditQuery;
use App\Services\Admin\CronSchedule;
use App\Services\AuditLogger;
use App\Services\Notify;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * PROC-061 (spec 09 §4.8, EXP-BR-01…03): Solicitud → Permiso → Job → Procesar → Archivo → Disponible → Descarga → Auditoría.
 * La bitácora (`auditoria`) se exporta por el mismo flujo con `admin.audit.view` (AUD-BR-03).
 */
class ExportService
{
    public const FORMATS = ['CSV', 'XLSX', 'PDF'];

    public const FREQUENCIES = ['diario' => 'Diario', 'lunes' => 'Semanal (lunes)', 'viernes' => 'Semanal (viernes)', 'mensual' => 'Mensual (día 1)'];

    public function __construct(
        private readonly ReportService $reports,
        private readonly AuditQuery $auditQuery,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
        private readonly TenantContext $context,
    ) {}

    /** EXP-BR-01: valida permiso (o audita `exportar_denegado`), crea la exportación y encola el job. */
    public function request(User $user, array $data, ?string $idempotencyKey = null): ReportExport
    {
        $key = $data['report_key'];
        $this->authorize($user, $key);
        if ($idempotencyKey && ($existing = ReportExport::where('idempotency_key', $idempotencyKey)->first())) {
            return $existing;
        }
        $export = ReportExport::create([
            'report_name' => $this->name($key, $data['filters'] ?? []), 'report_key' => $key, 'filters' => $data['filters'] ?? [],
            'scope' => $data['scope'] ?? 'filtered', 'format' => $data['format'], 'status' => 'en_cola', 'requested_by' => $user->id,
            'idempotency_key' => $idempotencyKey, 'expires_at' => now()->addDays((int) config('turismo.exports_ttl_days', 7)), 'created_at' => now(),
        ]);
        $this->audit->log('Exportación', $export->displayCode(), 'exportar', null, "{$export->report_name} · {$export->format}");
        GenerateExport::dispatch($export->id)->afterCommit();

        return $export->refresh();
    }

    public function authorize(User $user, string $key): void
    {
        $required = $key === 'auditoria' ? ['admin.audit.view'] : ['reports.export'];
        $allowed = $key === 'auditoria'
            ? $user->hasPermissionTo('admin.audit.view')
            : $user->hasPermissionTo('reports.export') && in_array($key, $this->reports->tabsFor($user), true);
        if (! $allowed) {
            $this->audit->isolated('Exportación', $key, 'exportar_denegado', $user->roleName(), 'requiere '.implode(' | ', $required));

            throw ApiException::forbidden($key === 'auditoria' ? 'AUD-BR-03' : 'REP-BR-05', 'Requiere permiso '.implode(' | ', $required), $required);
        }
    }

    /** Job `GenerateExport`: procesando → archivo en `tenants/{tenant}/exports/{uuid}.{ext}` → disponible (o fallido). */
    public function generate(ReportExport $export, ?User $as = null): ReportExport
    {
        if (in_array($export->status, ['disponible', 'expirado'], true)) {
            return $export;
        }
        $export->forceFill(['status' => 'procesando'])->save();
        try {
            $user = $as ?? $export->requester;
            [$header, $rows] = $this->table($export, $user);
            $bytes = FileWriter::write($export->format, $export->report_name, $header, $rows);
            $path = 'tenants/'.$export->tenant_id.'/exports/'.$export->id.'.'.strtolower($export->format);
            Storage::disk(config('turismo.exports_disk'))->put($path, $bytes);
            $export->forceFill(['status' => 'disponible', 'file_path' => $path, 'rows_count' => count($rows), 'size_bytes' => strlen($bytes), 'finished_at' => now(), 'error' => null])->save();
        } catch (Throwable $e) {
            $export->forceFill(['status' => 'fallido', 'error' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => now()])->save();
            report($e);

            return $export;
        }
        if ($export->requested_by) {
            $this->notify->push('exportacion.lista', 'portal', $export->requester, "Exportación lista: {$export->report_name}",
                "{$export->displayCode()} ({$export->format}, {$export->rows_count} filas) disponible por 7 días en Reportes › Exportaciones.",
                ['related_type' => 'report_export', 'related_id' => $export->id]);
        }

        return $export;
    }

    /** JOB-08 / JOB-nn: se genera en línea como Sistema con los permisos de quien lo programó (o un Manager). */
    public function scheduled(ScheduledJob $job): ReportExport
    {
        $key = $job->report_key ?? 'comercial';
        $owner = $job->owner ?? User::role(['manager', 'tenant_admin'])->where('status', User::ACTIVE)->orderBy('code')->first();
        $export = ReportExport::create([
            'report_name' => $this->name($key, ['period' => 'mes']).' (programado)', 'report_key' => $key, 'filters' => ['period' => 'mes'],
            'scope' => 'filtered', 'format' => $job->report_format ?? 'PDF', 'status' => 'en_cola', 'requested_by' => null, 'scheduled_job_id' => $job->id,
            'expires_at' => now()->addDays((int) config('turismo.exports_ttl_days', 7)), 'created_at' => now(),
        ]);
        $this->audit->log('Exportación', $export->displayCode(), 'exportar', null, "{$export->report_name} · {$job->code}", system: true);

        return $this->generate($export, $owner);
    }

    /** Historial (15 últimos) + envíos programados. */
    public function history(User $user): array
    {
        $exports = ReportExport::with('requester:id,name')->orderByDesc('created_at')->limit(15)->get();

        return [
            'data' => $exports->map(fn (ReportExport $e) => $this->row($e))->all(),
            'schedules' => ScheduledJob::with('owner:id,name')->whereNotNull('report_key')->orderBy('code')->get()->map(fn (ScheduledJob $j) => [
                'id' => $j->id, 'code' => $j->code, 'name' => $j->name, 'report_key' => $j->report_key, 'format' => $j->report_format, 'cron_label' => $j->cron_label,
                'recipients' => $j->recipients, 'owner' => $j->owner?->name, 'paused' => $j->paused, 'next_run_at' => $j->next_run_at?->toIso8601String(),
                'can_delete' => $user->hasPermissionTo('admin.settings.manage') || $j->owner_user_id === $user->id,
            ])->all(),
            'meta' => ['formats' => self::FORMATS, 'frequencies' => self::FREQUENCIES, 'ttl_days' => (int) config('turismo.exports_ttl_days', 7)],
        ];
    }

    public function row(ReportExport $e): array
    {
        $status = $e->effectiveStatus();

        return [
            'id' => $e->id, 'code' => $e->displayCode(), 'report_name' => $e->report_name, 'report_key' => $e->report_key, 'format' => $e->format, 'scope' => $e->scope,
            'status' => $status, 'status_label' => ReportExport::STATUS[$status], 'rows' => $e->rows_count, 'size_bytes' => $e->size_bytes,
            'requested_by' => $e->requested_by ? $e->requester?->name : 'Sistema', 'created_at' => $e->created_at?->toIso8601String(),
            'expires_at' => $e->expires_at->toIso8601String(), 'error' => $e->error, 'can_download' => $status === 'disponible',
        ];
    }

    /** EXP-BR-02: cada descarga se audita; responde una URL firmada válida 5 minutos. */
    public function download(User $user, string $id): string
    {
        $export = ReportExport::find($id) ?? throw ApiException::notFound();
        if ($export->report_key === 'auditoria' ? ! $user->hasPermissionTo('admin.audit.view') : ! $user->hasPermissionTo('reports.export')) {
            throw ApiException::forbidden('REP-BR-05', 'Requiere permiso reports.export', ['reports.export']);
        }
        if ($export->effectiveStatus() !== 'disponible') {
            throw ApiException::conflict('EXPORT_NOT_AVAILABLE', $export->effectiveStatus() === 'expirado'
                ? 'El archivo expiró (disponible 7 días). Vuelva a generarlo.' : 'El archivo aún no está disponible');
        }
        $this->audit->log('Exportación', $export->displayCode(), 'descargar', null, $export->report_name);

        return URL::temporarySignedRoute('exports.file', now()->addMinutes(5), ['export' => $export->id, 'tenant' => $export->tenant_id]);
    }

    /** Descarga pública por URL firmada (sin sesión). */
    public function file(string $tenantId, string $id): array
    {
        return $this->context->run($tenantId, function () use ($id) {
            $export = ReportExport::find($id) ?? throw ApiException::notFound();
            if ($export->effectiveStatus() !== 'disponible' || ! Storage::disk(config('turismo.exports_disk'))->exists($export->file_path)) {
                throw ApiException::notFound();
            }

            return [Storage::disk(config('turismo.exports_disk'))->get($export->file_path), $export];
        });
    }

    /** EXP-BR-03: crea un JOB-nn en el scheduler con formato, frecuencia y destinatarios propios. */
    public function schedule(User $user, array $data): ScheduledJob
    {
        $this->authorize($user, $data['report_key']);
        $emails = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $data['recipients'])))));
        if ($emails === []) {
            throw ApiException::invalid('recipients', 'Indique al menos un destinatario');
        }
        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ApiException::invalid('recipients', "Correo inválido: {$email}");
            }
        }
        [$cron, $label] = CronSchedule::forReport($data['frequency'], $data['time']);

        return DB::transaction(function () use ($user, $data, $emails, $cron, $label) {
            DB::table('sys_scheduled_jobs')->lockForUpdate()->where('tenant_id', $this->context->id())->get(['id']);
            $next = (int) ScheduledJob::pluck('code')->map(fn ($c) => (int) Str::after($c, 'JOB-'))->max() + 1;
            $job = ScheduledJob::create([
                'code' => sprintf('JOB-%02d', max(10, $next)), 'name' => 'Reporte programado: '.(ReportService::REPORTS[$data['report_key']] ?? $data['report_key']),
                'job_class' => 'GenerateScheduledReport', 'cron' => $cron, 'cron_label' => $label, 'queue' => 'reports', 'paused' => false,
                'next_run_at' => CronSchedule::next($cron), 'report_key' => $data['report_key'], 'report_format' => $data['format'],
                'recipients' => implode(', ', $emails), 'owner_user_id' => $user->id, 'is_system' => false,
            ]);
            $this->audit->log('Job', $job->code, 'programar', null, "{$job->name} · {$label} · {$data['format']} → ".implode(', ', $emails));

            return $job;
        });
    }

    /** @return array{0: list<string>, 1: list<list<scalar|null>>} */
    private function table(ReportExport $export, ?User $user): array
    {
        if ($export->report_key === 'auditoria') {
            return $this->auditQuery->exportRows($export->filters ?? []);
        }
        if (! $user) {
            throw new \RuntimeException('Sin usuario para evaluar el alcance del reporte');
        }
        $report = $this->reports->build($export->report_key, $user, $export->filters ?? []);
        $columns = $report['table']['columns'];
        $rows = array_map(fn ($r) => array_map(fn ($c) => $this->cell($r[$c[0]] ?? null), $columns), $report['table']['rows']);
        if (! empty($report['table']['totals'])) {
            $rows[] = array_map(fn ($c) => $this->cell($report['table']['totals'][$c[0]] ?? null), $columns);
        }

        return [array_map(fn ($c) => $c[1], $columns), $rows];
    }

    private function cell(mixed $v): string|int|float|null
    {
        return is_bool($v) ? ($v ? 'sí' : 'no') : (is_array($v) ? implode(', ', $v) : $v);
    }

    private function name(string $key, array $filters): string
    {
        if ($key === 'auditoria') {
            return 'Bitácora de auditoría · '.(AuditQuery::PERIODS[$filters['period'] ?? '30'] ?? 'Últimos 30 días');
        }

        return (ReportService::REPORTS[$key] ?? throw ApiException::invalid('report_key', 'Reporte desconocido'))
            .' · '.(ReportService::PERIODS[$filters['period'] ?? '6m'] ?? ReportService::PERIODS['6m']);
    }
}
