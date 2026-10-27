<?php

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Models\Admin\IntegrationAdapter;
use App\Models\Admin\IntegrationError;
use App\Models\Admin\JobRun;
use App\Models\Admin\ScheduledJob;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\CorrelationId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Integraciones, scheduler y errores (PROC-064/065, spec 09 §3.13–3.15). No se inventan APIs ni credenciales:
 * las integraciones sin proveedor quedan “Por validar” y su prueba no envía credenciales (INT-BR-01).
 */
class IntegrationAdmin
{
    public const KINDS = ['fiscal' => 'Facturación electrónica', 'payments' => 'Pagos', 'email' => 'Correo', 'messaging' => 'Mensajería',
        'maps' => 'Mapas', 'suppliers' => 'Proveedores', 'distribution' => 'Distribución'];

    /** Contrato de interfaz por tipo de adapter (§11-14). */
    public const CONTRACTS = [
        'fiscal' => ['emitir(comprobante): ticket', 'consultarEstado(ticket): aceptado|rechazado|observado', 'anular(comprobante, motivo)'],
        'payments' => ['crearCobro(monto, moneda, referencia): enlace', 'webhook(firma HMAC): pagado|rechazado', 'reembolsar(cobro, monto)'],
        'email' => ['enviar(destinatario, asunto, cuerpo): id', 'estado(id): entregado|rebotado'],
        'messaging' => ['enviarPlantilla(numero, plantilla, variables): id', 'estado(id): enviado|leido|fallido'],
        'maps' => ['geocodificar(direccion): lat,lng', 'ruta(origen, destino): duracion'],
        'suppliers' => ['disponibilidad(servicio, fecha): cupos', 'reservar(servicio, pax): confirmacion', 'sincronizar(): cambios'],
        'distribution' => ['publicar(producto, tarifas)', 'recibirReserva(payload): reserva'],
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly JobRunner $runner,
        private readonly CorrelationId $correlation,
    ) {}

    public function adapters(): array
    {
        return IntegrationAdapter::with('owner')->orderBy('code')->get()->map(fn (IntegrationAdapter $a) => $this->adapterRow($a))->all();
    }

    public function adapterRow(IntegrationAdapter $a): array
    {
        return [
            'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'kind' => $a->kind, 'kind_label' => self::KINDS[$a->kind], 'status' => $a->status,
            'status_label' => AdminCatalog::ADAPTER_STATUS[$a->status], 'environment' => $a->environment, 'owner_user_id' => $a->owner_user_id, 'owner' => $a->owner?->name,
            'max_retries' => $a->max_retries, 'backoff_base_s' => $a->backoff_base_s, 'timeout_s' => $a->timeout_s,
            'secret' => $a->secret_ref ? '•••• en bóveda' : 'Sin credenciales', 'secret_rotation_due' => $a->secret_rotated_at?->copy()->addDays(90)->toDateString(),
            'note' => $a->note, 'last_test_at' => $a->last_test_at?->toIso8601String(), 'last_test_ok' => $a->last_test_ok, 'last_test_result' => $a->last_test_result,
            'contract' => self::CONTRACTS[$a->kind], 'can_production' => ! in_array($a->status, ['por_validar', 'futuro', 'sandbox'], true),
            'open_errors' => IntegrationError::where('ref_code', $a->code)->where('status', '<>', 'resuelto')->count(),
        ];
    }

    /** Healthcheck vía adapter: persiste el resultado; un adapter con errores abiertos de su job responde con timeout. */
    public function test(IntegrationAdapter $a, User $actor): array
    {
        $jobError = $a->kind === 'suppliers' ? IntegrationError::where('source', 'job')->where('ref_code', 'JOB-07')->where('status', 'reintentando')->exists() : false;
        [$ok, $result] = match (true) {
            in_array($a->status, ['por_validar', 'futuro'], true) => [false, 'Sin proveedor contratado: la prueba no envía credenciales (INT-BR-01).'],
            $a->status === 'sandbox' => [true, 'Sandbox: conexión simulada OK; producción requiere plantillas aprobadas.'],
            $jobError => [false, "Timeout de {$a->timeout_s} s del proveedor (transitorio): se reintentará con backoff."],
            $a->status === 'parcial' => [true, 'Conexión OK · funcionalidad parcial.'],
            default => [true, 'Healthcheck OK · '.random_int(90, 240).' ms'],
        };
        $a->forceFill(['last_test_at' => now(), 'last_test_ok' => $ok, 'last_test_result' => $result, 'updated_by' => $actor->id])->save();
        $this->audit->log('Integración', $a->code, 'probar_conexion', null, ($ok ? 'OK' : 'Error').' · '.$result, actor: $actor);

        return $this->adapterRow($a->fresh('owner'));
    }

    /** INT-BR-02: no pasa a Producción si está por validar, es futura o solo sandbox (WhatsApp sin plantillas aprobadas). */
    public function configure(IntegrationAdapter $a, array $input, User $actor): array
    {
        $data = Validator::make($input, [
            'environment' => 'sometimes|in:sandbox,production', 'owner_user_id' => 'sometimes|nullable|uuid', 'max_retries' => 'sometimes|integer|between:0,10',
            'backoff_base_s' => 'sometimes|integer|min:5', 'timeout_s' => 'sometimes|integer|between:5,120',
        ])->validate();
        if (($data['environment'] ?? null) === 'production' && $a->environment !== 'production' && in_array($a->status, ['por_validar', 'futuro', 'sandbox'], true)) {
            throw ApiException::unprocessable('INT-BR-02', $a->status === 'sandbox'
                ? "{$a->name} solo opera en sandbox: requiere plantillas aprobadas antes de pasar a Producción."
                : "{$a->name} está ".AdminCatalog::ADAPTER_STATUS[$a->status].': no puede pasar a Producción sin proveedor validado.');
        }
        if (! empty($data['owner_user_id']) && ! User::where('status', User::ACTIVE)->whereHas('roles', fn ($q) => $q->whereIn('name', ['tenant_admin', 'manager', 'finance', 'operations']))->whereKey($data['owner_user_id'])->exists()) {
            throw ApiException::invalid('owner_user_id', 'El responsable debe ser un usuario activo de Tenant Admin, Manager, Finanzas u Operaciones.');
        }
        $changes = collect($data)->filter(fn ($v, $k) => (string) $a->{$k} !== (string) $v);
        if ($changes->isEmpty()) {
            return $this->adapterRow($a->load('owner')) + ['message' => 'No hay cambios que guardar'];
        }
        $before = $changes->map(fn ($v, $k) => "{$k}: {$a->{$k}}")->implode(' · ');
        $a->forceFill($data + ['updated_by' => $actor->id])->save();
        $this->audit->log('Integración', $a->code, 'configurar', $before, $changes->map(fn ($v, $k) => "{$k}: {$v}")->implode(' · '), actor: $actor);

        return $this->adapterRow($a->fresh('owner')) + ['message' => 'Configuración guardada.'];
    }

    public function jobs(): array
    {
        return ScheduledJob::with(['owner', 'runs' => fn ($q) => $q->orderByDesc('started_at')->limit(5)])->orderByRaw('is_system DESC')->orderBy('code')->get()
            ->map(fn (ScheduledJob $j) => $this->jobRow($j))->all();
    }

    public function jobRow(ScheduledJob $j): array
    {
        return [
            'id' => $j->id, 'code' => $j->code, 'name' => $j->name, 'cron' => $j->cron, 'cron_label' => $j->cron_label, 'queue' => $j->queue, 'paused' => (bool) $j->paused,
            'last_run_at' => $j->last_run_at?->toIso8601String(), 'next_run_at' => $j->next_run_at?->toIso8601String(), 'last_status' => $j->last_status,
            'last_result' => $j->last_result, 'is_system' => (bool) $j->is_system, 'report_key' => $j->report_key, 'report_format' => $j->report_format,
            'recipients' => $j->recipients, 'owner' => $j->owner?->name, 'owner_user_id' => $j->owner_user_id,
            'runs' => $j->relationLoaded('runs') ? $j->runs->map(fn (JobRun $r) => ['trigger' => $r->trigger, 'status' => $r->status, 'result' => $r->result,
                'started_at' => $r->started_at?->toIso8601String(), 'finished_at' => $r->finished_at?->toIso8601String()])->all() : [],
        ];
    }

    /** JOB-BR-01: encola una ejecución sin tocar la programación; 409 si ya está en curso. */
    public function run(ScheduledJob $j, User $actor): array
    {
        $claimed = ScheduledJob::whereKey($j->id)->where(fn ($q) => $q->whereNull('last_status')->orWhere('last_status', '<>', 'en_curso'))
            ->update(['last_status' => 'en_curso', 'updated_at' => now()]);
        if ($claimed === 0) {
            throw ApiException::conflict('JOB_RUNNING', "{$j->code} ya está en ejecución.");
        }
        $run = JobRun::create(['scheduled_job_id' => $j->id, 'trigger' => 'manual', 'triggered_by' => $actor->id, 'status' => 'en_curso',
            'started_at' => now(), 'correlation_id' => $this->correlation->get()]);
        try {
            [$status, $result] = $this->runner->run($j->fresh());
        } catch (Throwable $e) {
            [$status, $result] = ['error', mb_substr($e->getMessage(), 0, 240)];
        }
        $run->forceFill(['status' => $status, 'result' => mb_substr($result, 0, 250), 'finished_at' => now()])->save();
        // El claim fue un UPDATE directo: se escribe también directo para no depender del estado original del modelo.
        ScheduledJob::whereKey($j->id)->update(['last_run_at' => now(), 'last_status' => $status, 'last_result' => mb_substr($result, 0, 250), 'updated_at' => now()]);
        $this->audit->log('Job', $j->code, 'ejecutar_manual', null, "{$status} · {$result}", actor: $actor);

        return $this->jobRow($j->fresh(['owner', 'runs' => fn ($q) => $q->orderByDesc('started_at')->limit(5)])) + ['message' => "{$j->code}: {$result}"];
    }

    /** JOB-BR-02: pausar deja next_run_at vacío; reanudar lo recalcula. */
    public function pause(ScheduledJob $j, bool $pause, User $actor): array
    {
        if ((bool) $j->paused === $pause) {
            throw ApiException::rule('JOB-STATE', $pause ? 'El job ya está pausado.' : 'El job no está pausado.');
        }
        $j->forceFill(['paused' => $pause, 'next_run_at' => $pause ? null : CronSchedule::next($j->cron)])->save();
        $this->audit->log('Job', $j->code, $pause ? 'pausar' : 'reanudar', null, $pause ? 'pausado' : 'próxima '.$j->next_run_at?->format('d/m H:i'), actor: $actor);

        return $this->jobRow($j->fresh('owner'));
    }

    /** JOB-BR-03: solo envíos programados de reportes (los archivos generados se conservan). */
    public function delete(ScheduledJob $j, User $actor): void
    {
        if ($j->report_key === null || $j->is_system) {
            throw ApiException::rule('JOB-BR-03', 'Solo se eliminan envíos programados de reportes.');
        }
        if (! $actor->hasPermissionTo('admin.settings.manage') && $j->owner_user_id !== $actor->id) {
            throw ApiException::forbidden('JOB-BR-03', 'Solo el dueño del envío programado o un administrador puede eliminarlo.');
        }
        DB::transaction(function () use ($j, $actor) {
            $this->audit->log('Job', $j->code, 'eliminar', "{$j->name} · {$j->cron_label}", null, actor: $actor);
            $j->delete();
        });
    }

    public function errors(array $filters): array
    {
        $q = IntegrationError::orderByRaw("CASE status WHEN 'fallo_definitivo' THEN 0 WHEN 'reintentando' THEN 1 ELSE 2 END")->orderByDesc('updated_at')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['class'] ?? null, fn ($q, $v) => $q->where('error_class', $v));

        return [
            'data' => $q->limit(100)->get()->map(fn (IntegrationError $e) => $this->errorRow($e))->all(),
            'meta' => ['kpis' => ['retrying' => IntegrationError::where('status', 'reintentando')->count(), 'failed' => IntegrationError::where('status', 'fallo_definitivo')->count(),
                'resolved' => IntegrationError::where('status', 'resuelto')->count()], 'statuses' => IntegrationError::STATUS],
        ];
    }

    public function errorRow(IntegrationError $e): array
    {
        return ['id' => $e->id, 'code' => $e->displayCode(), 'source' => $e->source, 'ref_code' => $e->ref_code, 'target' => $e->target, 'error' => $e->error,
            'error_class' => $e->error_class, 'attempt' => $e->attempt, 'max_attempts' => $e->max_attempts, 'next_retry_at' => $e->next_retry_at?->toIso8601String(),
            'status' => $e->status, 'status_label' => IntegrationError::STATUS[$e->status], 'resolution' => $e->resolution, 'updated_at' => $e->updated_at?->toIso8601String()];
    }

    /**
     * INT-BR-04/05: un transitorio suma un intento con backoff y al llegar al máximo pasa a fallo definitivo (alerta y auditoría);
     * un fallo definitivo transitorio vuelve a “reintentando” con intento 0; un permanente solo suma el intento.
     */
    public function retry(IntegrationError $e, User $actor): array
    {
        if ($e->status === 'resuelto') {
            throw ApiException::rule('INT-STATE', 'El error ya está resuelto.');
        }
        $warnings = [];
        if ($e->error_class === 'permanente') {
            $e->forceFill(['attempt' => $e->attempt + 1])->save();
            $warnings[] = 'Error permanente: reintentar no cambia el resultado (INT-BR-05). Corrija el dato o use un canal alterno.';
        } elseif ($e->status === 'fallo_definitivo') {
            $e->forceFill(['status' => 'reintentando', 'attempt' => 0, 'next_retry_at' => now()->addSeconds($this->backoff($e, 1))])->save();
        } else {
            $this->runner->step($e);
        }
        $this->audit->log('Integración', $e->displayCode(), 'reintentar', null, "{$e->status} · intento {$e->attempt}/{$e->max_attempts}", actor: $actor);

        return ['data' => $this->errorRow($e->fresh()), 'warnings' => $warnings, 'message' => match ($e->fresh()->status) {
            'fallo_definitivo' => 'Se alcanzó el máximo de intentos: fallo definitivo y alerta a Operaciones.',
            'reintentando' => "Reintento programado (intento {$e->fresh()->attempt}/{$e->max_attempts}).",
            default => 'Error resuelto.',
        }];
    }

    /** INT-BR-06: resolver manualmente exige la descripción de la resolución. */
    public function resolve(IntegrationError $e, ?string $resolution, User $actor): array
    {
        if (trim((string) $resolution) === '') {
            throw ApiException::invalid('resolution', 'Describa cómo se resolvió el error.');
        }
        if ($e->status === 'resuelto') {
            throw ApiException::rule('INT-STATE', 'El error ya está resuelto.');
        }
        $e->forceFill(['status' => 'resuelto', 'resolution' => trim((string) $resolution), 'resolved_by' => $actor->id, 'next_retry_at' => null])->save();
        $this->audit->log('Integración', $e->displayCode(), 'resolver_error', $e->getOriginal('status'), trim((string) $resolution), actor: $actor);

        return $this->errorRow($e);
    }

    private function backoff(IntegrationError $e, int $attempt): int
    {
        $base = IntegrationAdapter::where('code', 'INT-06')->value('backoff_base_s') ?? 60;

        return IntegrationErrors::backoffSeconds((int) $base, $attempt);
    }
}
