<?php

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Models\Admin\IntegrationError;
use App\Models\Admin\NotifRule;
use App\Models\Admin\NotifTemplate;
use App\Models\Iam\User;
use App\Models\Sys\NotifMessage;
use App\Notifications\Channels\PermanentDeliveryFailure;
use App\Services\AuditLogger;
use App\Services\Notify;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Notificaciones (PROC-059, spec 09 §3.10–3.12): matriz evento × canal con su regla, plantillas versionadas con lista
 * cerrada de variables y log de envíos con reintento y canal alterno.
 */
class NotificationAdmin
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    public function rules(): array
    {
        $rows = NotifRule::all()->keyBy('event_code');

        return [
            'data' => collect(AdminCatalog::EVENTS)->map(function ($e, $code) use ($rows) {
                $r = $rows[$code] ?? null;

                return ['event' => $code, 'label' => $e[0], 'email' => (bool) $r?->ch_email, 'whatsapp' => (bool) $r?->ch_whatsapp, 'push' => (bool) $r?->ch_push,
                    'portal' => (bool) $r?->ch_portal, 'recipients' => $r?->recipients ?? $e[5], 'timing' => $r?->timing ?? 'immediate', 'condition' => $r?->condition ?? 'always',
                    'critical' => $code === 'incidencia.critica'];
            })->values()->all(),
            'meta' => ['timings' => AdminCatalog::TIMINGS, 'conditions' => AdminCatalog::CONDITIONS, 'variables' => AdminCatalog::VARIABLES,
                'system_events' => ['tenant.activado', 'tenant.suspendido', 'tenant.reactivado', 'usuario.invitado', 'seguridad.mfa_reset', 'acceso.solicitud',
                    'exportacion.lista', 'reporte.programado', 'integracion.fallo', 'pago.recordatorio', 'viaje.proximo', 'cotizacion.aprobacion_interna']],
        ];
    }

    /** NTF-BR-01: la incidencia crítica conserva al menos un canal. Cada canal y cada cambio de regla se audita. */
    public function updateRule(string $event, array $input, User $actor): array
    {
        $rule = NotifRule::where('event_code', $event)->first() ?? throw ApiException::notFound();
        $data = Validator::make($input, [
            'email' => 'sometimes|boolean', 'whatsapp' => 'sometimes|boolean', 'push' => 'sometimes|boolean', 'portal' => 'sometimes|boolean',
            'recipients' => 'sometimes|required|string|max:200', 'timing' => 'sometimes|in:'.implode(',', array_keys(AdminCatalog::TIMINGS)),
            'condition' => 'sometimes|in:'.implode(',', array_keys(AdminCatalog::CONDITIONS)),
        ])->validate();
        $next = [];
        foreach (NotifRule::CHANNELS as $channel => $col) {
            $next[$col] = array_key_exists($channel, $data) ? (bool) $data[$channel] : (bool) $rule->{$col};
        }
        if ($event === 'incidencia.critica' && ! in_array(true, $next, true)) {
            throw ApiException::unprocessable('NTF-BR-01', 'La incidencia crítica debe notificarse por al menos un canal.');
        }
        DB::transaction(function () use ($rule, $data, $next, $actor, $event) {
            foreach (NotifRule::CHANNELS as $channel => $col) {
                if ($next[$col] !== (bool) $rule->{$col}) {
                    $this->audit->log('Notificación', "{$event}.{$channel}", 'actualizar', $rule->{$col} ? 'activo' : 'inactivo', $next[$col] ? 'activo' : 'inactivo', actor: $actor);
                }
            }
            $changes = [];
            foreach (['recipients', 'timing', 'condition'] as $f) {
                if (isset($data[$f]) && $data[$f] !== $rule->{$f}) {
                    $changes[] = "{$f}: {$rule->{$f}} → {$data[$f]}";
                    $rule->{$f} = $data[$f];
                }
            }
            if ($changes) {
                $this->audit->log('Notificación', $event, 'actualizar_regla', null, implode(' · ', $changes), actor: $actor);
            }
            $rule->forceFill($next + ['updated_by' => $actor->id, 'updated_at' => now()])->save();
        });

        return collect($this->rules()['data'])->firstWhere('event', $event);
    }

    /** NTF-BR-02: probar sin canales activos solo avisa. */
    public function testRule(string $event, User $actor): array
    {
        $rule = NotifRule::where('event_code', $event)->first() ?? throw ApiException::notFound();
        $channels = $rule->activeChannels();
        if ($channels === []) {
            return ['sent' => 0, 'message' => 'El evento no tiene canales activos: no se envió ninguna prueba.', 'warnings' => ['Active al menos un canal para probarlo.']];
        }
        foreach ($channels as $channel) {
            $this->sendTest($event, $channel, $actor);
        }

        return ['sent' => count($channels), 'message' => 'Prueba enviada a '.$actor->name.' por '.implode(', ', $channels).'.'];
    }

    public function template(string $event, string $channel): array
    {
        $this->assertEventChannel($event, $channel);
        $current = NotifTemplate::current($event, $channel);
        $history = NotifTemplate::where('event_code', $event)->where('channel', $channel)->where('tenant_id', $this->context->id())->orderByDesc('version')->limit(10)->get();

        return [
            'event' => $event, 'label' => AdminCatalog::EVENTS[$event][0], 'channel' => $channel, 'subject' => $current?->subject, 'body' => $current?->body,
            'version' => (int) $current?->version, 'is_default' => (bool) $current?->isSystem(), 'provider_template_id' => $current?->provider_template_id,
            'max_length' => $channel === 'push' ? 140 : null, 'variables' => AdminCatalog::VARIABLES,
            'preview' => $current ? $this->render($current->subject, $current->body) : null,
            'history' => $history->map(fn (NotifTemplate $t) => ['version' => $t->version, 'subject' => $t->subject, 'current' => $t->is_current, 'at' => $t->created_at])->all(),
        ];
    }

    /** NTF-BR-03/04/05: variables permitidas, push ≤ 140 y cada guardado es una versión nueva. */
    public function saveTemplate(string $event, string $channel, array $input, User $actor): array
    {
        $this->assertEventChannel($event, $channel);
        $data = Validator::make($input, ['subject' => 'required|string|max:200', 'body' => 'required|string|max:5000'])->validate();
        $this->assertVariables($data['subject'].' '.$data['body']);
        if ($channel === 'push' && mb_strlen($data['body']) > 140) {
            throw ApiException::unprocessable('NTF-BR-04', 'El cuerpo de una notificación push admite máximo 140 caracteres ('.mb_strlen($data['body']).').');
        }
        $current = NotifTemplate::current($event, $channel);
        if ($current && $current->subject === $data['subject'] && $current->body === $data['body']) {
            return $this->template($event, $channel) + ['message' => 'No hay cambios en la plantilla.', 'warnings' => ['No hay cambios que guardar.']];
        }
        $version = max((int) NotifTemplate::where('event_code', $event)->where('channel', $channel)->max('version'), 0) + 1;
        DB::transaction(function () use ($event, $channel, $data, $version, $current, $actor) {
            NotifTemplate::where('tenant_id', $this->context->id())->where('event_code', $event)->where('channel', $channel)->update(['is_current' => false]);
            NotifTemplate::create(['tenant_id' => $this->context->id(), 'event_code' => $event, 'channel' => $channel, 'version' => $version,
                'subject' => $data['subject'], 'body' => $data['body'], 'is_current' => true, 'created_by' => $actor->id]);
            $this->audit->log('Plantilla', "{$event}/{$channel}", 'actualizar', 'v'.($current?->version ?? 1), "v{$version}", actor: $actor);
        });

        return $this->template($event, $channel) + ['message' => "Plantilla guardada como versión {$version}."];
    }

    public function restore(string $event, string $channel, User $actor): array
    {
        $this->assertEventChannel($event, $channel);
        $updated = NotifTemplate::where('tenant_id', $this->context->id())->where('event_code', $event)->where('channel', $channel)->where('is_current', true)->update(['is_current' => false]);
        if ($updated) {
            $this->audit->log('Plantilla', "{$event}/{$channel}", 'restaurar', null, 'predeterminada del sistema', actor: $actor);
        }

        return $this->template($event, $channel) + ['message' => $updated ? 'Se restauró la plantilla predeterminada.' : 'La plantilla ya era la predeterminada.'];
    }

    public function testTemplate(string $event, string $channel, array $input, User $actor): array
    {
        $this->assertEventChannel($event, $channel);
        if (isset($input['subject']) || isset($input['body'])) {
            $this->assertVariables(($input['subject'] ?? '').' '.($input['body'] ?? ''));
        }
        $this->sendTest($event, $channel, $actor, $input['subject'] ?? null, $input['body'] ?? null);

        return ['message' => "Prueba de {$channel} enviada a {$actor->name}."];
    }

    public function messages(array $filters): array
    {
        $q = NotifMessage::query()->orderByDesc('created_at')
            ->when($filters['channel'] ?? null, fn ($q, $v) => $q->where('channel', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['event'] ?? null, fn ($q, $v) => $q->where('event', $v));
        $all = NotifMessage::query();

        return [
            'data' => $q->limit(100)->get()->map(fn (NotifMessage $m) => $this->messageRow($m))->all(),
            'meta' => [
                'kpis' => ['sent' => (clone $all)->where('status', 'enviado')->count(), 'failed' => (clone $all)->where('status', 'fallido')->count(),
                    'pending' => (clone $all)->where('status', 'pendiente')->count(), 'last_24h' => (clone $all)->where('created_at', '>=', now()->subDay())->count()],
                'events' => NotifMessage::query()->distinct()->orderBy('event')->pluck('event')->all(),
            ],
        ];
    }

    public function messageRow(NotifMessage $m): array
    {
        $permanent = $this->isPermanent($m);

        return ['id' => $m->id, 'code' => $m->displayCode(), 'event' => $m->event, 'channel' => $m->channel, 'recipient' => $m->recipient, 'subject' => $m->subject,
            'status' => $m->status, 'retries' => $m->retries, 'last_error' => $m->last_error, 'permanent' => $permanent, 'is_fallback' => (bool) $m->is_fallback,
            'can_retry' => $m->status === 'fallido', 'can_fallback' => $m->status === 'fallido' && $m->channel !== 'email' && ! $m->is_fallback && $this->fallbackAddress($m) !== null,
            'created_at' => $m->created_at?->toIso8601String(), 'sent_at' => $m->sent_at?->toIso8601String()];
    }

    /** NTF-BR-07: un error permanente no cambia al reintentar; sugiere canal alterno. */
    public function retry(NotifMessage $m, User $actor, bool $audit = true): array
    {
        if ($m->status !== 'fallido') {
            throw ApiException::rule('NTF-STATE', 'Solo se reintentan envíos fallidos.');
        }
        $warnings = [];
        try {
            $this->notify->sender($m->channel)->send($m);
            $m->forceFill(['status' => 'enviado', 'sent_at' => now(), 'last_error' => null, 'retries' => $m->retries + 1])->save();
            IntegrationError::where('source', 'notification')->where('ref_code', $m->displayCode())->where('status', '<>', 'resuelto')
                ->update(['status' => 'resuelto', 'resolution' => 'Reintento exitoso', 'resolved_by' => $actor->id]);
        } catch (PermanentDeliveryFailure $e) {
            $m->forceFill(['retries' => $m->retries + 1, 'last_error' => $e->getMessage()])->save();
            IntegrationErrors::permanentNotification($m, $e->getMessage());
            $warnings[] = 'Error permanente: reintentar no cambia el resultado. Reenvíe por canal alterno (Email).';
        } catch (Throwable $e) {
            $m->forceFill(['retries' => $m->retries + 1, 'last_error' => $e->getMessage()])->save();
            $warnings[] = 'El reintento volvió a fallar: '.$e->getMessage();
        }
        if ($audit) {
            $this->audit->log('Notificación', $m->displayCode(), 'reintentar', 'fallido', $m->status, actor: $actor);
        }

        return ['message' => $m->status === 'enviado' ? 'Reenvío exitoso.' : 'El envío sigue fallido.', 'warnings' => $warnings, 'data' => $this->messageRow($m->fresh())];
    }

    public function retryFailed(User $actor): array
    {
        $ok = 0;
        $failed = NotifMessage::where('status', 'fallido')->get()->reject(fn (NotifMessage $m) => $this->isPermanent($m));
        foreach ($failed as $m) {
            $ok += $this->retry($m, $actor, false)['data']['status'] === 'enviado' ? 1 : 0;
        }
        $this->audit->log('Notificación', 'fallidos', 'reintentar', $failed->count().' fallidos', "{$ok} enviados", actor: $actor);

        return ['retried' => $failed->count(), 'sent' => $ok, 'message' => "Se reintentaron {$failed->count()} envío(s) transitorio(s); {$ok} salieron bien."];
    }

    /** NTF-BR-08: canal alterno (Email) solo para envíos no Email que no sean ya alternos. */
    public function fallback(NotifMessage $m, User $actor): array
    {
        if ($m->channel === 'email' || $m->is_fallback) {
            throw ApiException::rule('NTF-BR-08', 'El canal alterno aplica solo a envíos que no son Email ni ya alternos.');
        }
        $address = $this->fallbackAddress($m) ?? throw ApiException::rule('NTF-BR-08', 'El destinatario no tiene correo registrado.');

        return DB::transaction(function () use ($m, $address, $actor) {
            $template = NotifTemplate::current($m->event, 'email');
            $copy = NotifMessage::create(['event' => $m->event, 'channel' => 'email', 'user_id' => $m->user_id, 'recipient' => $address, 'subject' => $m->subject,
                'body' => $m->body, 'data' => $m->data, 'status' => 'pendiente', 'is_fallback' => true, 'fallback_of_id' => $m->id, 'template_id' => $template?->id,
                'client_id' => $m->client_id, 'related_type' => $m->related_type, 'related_id' => $m->related_id]);
            try {
                $this->notify->sender('email')->send($copy);
                $copy->forceFill(['status' => 'enviado', 'sent_at' => now()])->save();
            } catch (Throwable $e) {
                $copy->forceFill(['status' => 'fallido', 'last_error' => $e->getMessage()])->save();
            }
            IntegrationError::where('source', 'notification')->where('ref_code', $m->displayCode())->where('status', '<>', 'resuelto')
                ->update(['status' => 'resuelto', 'resolution' => 'Reenviado por canal alterno ('.$copy->displayCode().')', 'resolved_by' => $actor->id]);
            $this->audit->log('Notificación', $m->displayCode(), 'canal_alterno', $m->channel, "email · {$copy->displayCode()}", actor: $actor);

            return ['message' => "Reenviado por Email a {$address} ({$copy->displayCode()}).", 'data' => $this->messageRow($copy)];
        });
    }

    private function sendTest(string $event, string $channel, User $actor, ?string $subject = null, ?string $body = null): void
    {
        $tpl = $channel === 'portal' ? NotifTemplate::current($event, 'email') : NotifTemplate::current($event, $channel);
        [$s, $b] = $this->render($subject ?? $tpl?->subject ?? AdminCatalog::EVENTS[$event][0], $body ?? $tpl?->body ?? '');
        $this->notify->push($event, $channel, $actor, '[Prueba] '.$s, $b, ['test' => true]);
    }

    /** Vista previa con datos de ejemplo. */
    public function render(string $subject, string $body): array
    {
        $sample = ['cliente' => 'Ana Gutiérrez', 'reserva' => 'RES-2026-000347', 'cotizacion' => 'COT-2026-00187', 'fecha_viaje' => '12/11/2026', 'monto' => 'S/ 4 850.00',
            'servicio' => 'Tour Valle Sagrado', 'hora' => '07:30', 'enlace' => rtrim((string) config('turismo.frontend_url'), '/').'/c/demo', 'agencia' => $this->context->tenant()?->trade_name ?? 'Agencia',
            'vendedor' => 'Carla Mendoza'];
        $fill = fn (string $t) => preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', fn ($m) => $sample[$m[1]] ?? $m[0], $t);

        return [$fill($subject), $fill($body)];
    }

    private function assertVariables(string $text): void
    {
        preg_match_all('/\{\{\s*([^}]*?)\s*\}\}/', $text, $m);
        $bad = array_values(array_unique(array_diff($m[1], AdminCatalog::VARIABLES)));
        if ($bad !== []) {
            throw ApiException::unprocessable('NTF-BR-03', 'Variables no permitidas: '.implode(', ', array_map(fn ($v) => '{{'.$v.'}}', $bad)).'. Use solo: '.implode(', ', AdminCatalog::VARIABLES).'.');
        }
    }

    private function assertEventChannel(string $event, string $channel): void
    {
        if (! isset(AdminCatalog::EVENTS[$event]) || ! in_array($channel, ['email', 'whatsapp', 'push'], true)) {
            throw ApiException::notFound();
        }
    }

    private function isPermanent(NotifMessage $m): bool
    {
        return IntegrationError::where('source', 'notification')->where('ref_code', $m->displayCode())->where('error_class', 'permanente')->exists()
            || str_contains((string) $m->last_error, '131026');
    }

    private function fallbackAddress(NotifMessage $m): ?string
    {
        if ($m->user_id) {
            return User::find($m->user_id)?->email;
        }

        return $m->data['email'] ?? (filter_var($m->recipient, FILTER_VALIDATE_EMAIL) ? $m->recipient : null);
    }
}
