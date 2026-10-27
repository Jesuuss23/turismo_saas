<?php

namespace App\Services\Admin;

use App\Models\Admin\IntegrationAdapter;
use App\Models\Admin\IntegrationError;
use App\Models\Admin\ScheduledJob;
use App\Models\Bookings\BookedService;
use App\Services\AuditLogger;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use App\Services\Reports\ExportService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;

/**
 * “Ejecutar ahora” de un job del scheduler (spec 09 §8.2) para el tenant actual. Los jobs de módulos reutilizan su comando
 * con `--tenant`; JOB-06, JOB-07 y los reportes programados se resuelven aquí.
 */
class JobRunner
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
        private readonly CrmClock $clock,
    ) {}

    /** @return array{0: string, 1: string} [estado, resultado] */
    public function run(ScheduledJob $job): array
    {
        if ($job->report_key !== null || $job->code === 'JOB-08') {
            return $this->report($job);
        }
        $command = AdminCatalog::JOBS[$job->code][4] ?? null;
        if ($command !== null) {
            Artisan::call($command[0], ($command[1] ?? []) + ['--tenant' => $this->context->id()]);
            $lines = array_values(array_filter(array_map('trim', explode("\n", Artisan::output()))));

            return ['ok', $lines === [] ? 'Ejecutado sin novedades' : end($lines)];
        }

        return match ($job->code) {
            'JOB-06' => $this->pendingSuppliers(),
            'JOB-07' => $this->syncSuppliers(),
            default => ['advertencia', 'Job sin implementación para ejecución manual'],
        };
    }

    /** JOB-06: servicios solicitados o pendientes de proveedor; si hay, queda en advertencia. */
    private function pendingSuppliers(): array
    {
        $n = BookedService::whereIn('status', [BookedService::REQUESTED, BookedService::PENDING])->whereNotNull('provider_id')
            ->where('service_date', '>=', $this->clock->today()->toDateString())->count();

        return $n > 0 ? ['advertencia', "{$n} servicio(s) pendientes de confirmación del proveedor"] : ['ok', 'Sin servicios pendientes de proveedor'];
    }

    /** JOB-07: reintenta las sincronizaciones fallidas con backoff 2^intento; al llegar al máximo, fallo definitivo + alerta. */
    private function syncSuppliers(): array
    {
        $open = IntegrationError::where('source', 'job')->where('ref_code', 'JOB-07')->where('status', 'reintentando')->get();
        if ($open->isEmpty()) {
            IntegrationAdapter::where('kind', 'suppliers')->update(['last_test_at' => now(), 'last_test_ok' => true, 'last_test_result' => 'Sincronización OK']);

            return ['ok', 'Sincronización de proveedores sin pendientes'];
        }
        foreach ($open as $e) {
            $this->step($e);
        }
        $failed = $open->filter(fn ($e) => $e->fresh()->status === 'fallo_definitivo')->count();

        return ['error', $open->first()->target.': '.$open->first()->error.' (intento '.$open->first()->fresh()->attempt.'/'.$open->first()->max_attempts.')'
            .($failed ? " · {$failed} fallo(s) definitivo(s)" : '')];
    }

    /** Un intento más de un error transitorio (INT-BR-04). */
    public function step(IntegrationError $e): void
    {
        $attempt = $e->attempt + 1;
        $adapter = IntegrationAdapter::where('code', 'INT-06')->first();
        if ($attempt >= $e->max_attempts) {
            $e->forceFill(['attempt' => $attempt, 'status' => 'fallo_definitivo', 'next_retry_at' => null])->save();
            $this->audit->log('Job', $e->ref_code, 'fallo_definitivo', "intento {$attempt}/{$e->max_attempts}", $e->error, system: true);
            foreach (CatalogRecipients::role('operations') as $user) {
                $this->notify->push('integracion.fallo', 'email', $user, "Fallo definitivo: {$e->target}",
                    "{$e->displayCode()} · {$e->error}\nSe agotaron {$e->max_attempts} intentos. Revise Administración › Integraciones › Errores.", ['related_type' => 'integration_error', 'related_id' => $e->id]);
            }

            return;
        }
        $e->forceFill(['attempt' => $attempt, 'next_retry_at' => now()->addSeconds(IntegrationErrors::backoffSeconds((int) ($adapter?->backoff_base_s ?? 60), $attempt))])->save();
    }

    /** JOB-08 y envíos programados: genera el archivo como Sistema y lo envía a los destinatarios. */
    private function report(ScheduledJob $job): array
    {
        $export = app(ExportService::class)->scheduled($job);
        $recipients = array_values(array_filter(array_map('trim', explode(',', (string) ($job->recipients ?? '')))));
        if ($recipients === []) {
            $recipients = CatalogRecipients::role(['manager', 'tenant_admin'])->pluck('email')->all();
        }
        foreach ($recipients as $to) {
            $this->notify->push('reporte.programado', 'email', $to, "Reporte programado: {$export->report_name}",
                "Adjuntamos {$export->displayCode()} ({$export->format}). Disponible por 7 días en Reportes › Exportaciones.", ['related_type' => 'report_export', 'related_id' => $export->id]);
        }

        return ['ok', "{$export->displayCode()} ({$export->format}) enviado a ".count($recipients).' destinatario(s)'];
    }
}
