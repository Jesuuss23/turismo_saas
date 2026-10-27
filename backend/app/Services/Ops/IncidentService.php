<?php

namespace App\Services\Ops;

use App\Exceptions\ApiException;
use App\Models\Bookings\BookedService;
use App\Models\Iam\User;
use App\Models\Ops\Incident;
use App\Models\Ops\IncidentLog;
use App\Models\Ops\Order;
use App\Services\AuditLogger;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Notify;
use App\Services\SequenceService;
use Illuminate\Support\Facades\DB;

/** Incidencias (PROC-037, §4.5): SLA por severidad (OPS-BR-60), alerta crítica (OPS-BR-61), reglas de cierre (OPS-BR-62). */
class IncidentService
{
    public function __construct(
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /**
     * Registrar (web, app operador, extranet). En móvil la descripción por defecto es el título (OPS-BR-64) y una
     * incidencia similar del coordinador en los últimos 10 min se vincula, no se fusiona (MOB-BR-11).
     */
    public function create(array $data, User $actor, string $source = 'web', ?string $clientActionId = null): Incident
    {
        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? '')) ?: ($source === 'app_operador' ? $title : '');
        if ($title === '' || $description === '') {
            throw ApiException::invalid($title === '' ? 'title' : 'description', 'Indique el título y la descripción de la incidencia (OPS-BR-64).');
        }
        if (! in_array($data['type'] ?? null, Incident::TYPES, true) || ! isset(Incident::SLA_HOURS[$data['severity'] ?? ''])) {
            throw ApiException::invalid('type', 'Elija el tipo y la severidad de la incidencia.');
        }
        $order = ! empty($data['order_id']) ? Order::find($data['order_id']) : null;
        $service = ! empty($data['reservation_service_id']) ? BookedService::find($data['reservation_service_id']) : null;
        if ($service && ! $order) {
            $order = Order::where('reservation_id', $service->reservation_id)->first();
        }
        $assignee = ! empty($data['assignee_id']) ? $this->assignee($data['assignee_id']) : $order?->coordinator;

        return DB::transaction(function () use ($data, $actor, $source, $clientActionId, $title, $description, $order, $service, $assignee) {
            $related = null;
            if ($source === 'app_operador' && $order) {
                $related = Incident::where('order_id', $order->id)->where('type', $data['type'])->where('reported_by', '<>', $actor->id)
                    ->where('created_at', '>=', now()->subMinutes(10))->latest()->first();
            }
            $hours = Incident::SLA_HOURS[$data['severity']];
            $incident = Incident::create([
                'code' => $this->sequences->next('incident'), 'order_id' => $order?->id, 'reservation_id' => $order?->reservation_id ?? $service?->reservation_id,
                'reservation_service_id' => $service?->id, 'provider_id' => $data['provider_id'] ?? $service?->provider_id,
                'type' => $data['type'], 'severity' => $data['severity'], 'title' => mb_substr($title, 0, 200), 'description' => $description,
                'status' => Incident::OPEN, 'assignee_id' => $assignee?->id, 'reported_by' => $actor->id, 'source' => $source,
                'sla_hours' => $hours, 'sla_due_at' => now()->addHours($hours), 'related_incident_id' => $related?->id, 'client_action_id' => $clientActionId,
            ]);
            $this->log($incident, 'registro', "Registrada por {$actor->name} (".self::SOURCES[$source].')'.($related ? " · vinculada a {$related->code} (posible duplicado, MOB-BR-11)" : ''), $actor);
            $this->audit->log('Incidencia', $incident->code, 'crear', null, Incident::SEVERITY_LABELS[$incident->severity]." · {$incident->type} · {$incident->title}", actor: $actor);
            $data = $this->data($incident);
            if ($order?->coordinator) {
                $this->notify->push('incidencia.creada', 'email', $order->coordinator, "Incidencia {$incident->code} · {$order->code}", $incident->title, $data);
                if ($related) {
                    $this->notify->push('incidencia.creada', 'portal', $order->coordinator, "Revise {$incident->code}: posible duplicado de {$related->code}", $incident->title, $data);
                }
            }
            if ($incident->severity === 'critica') {
                $this->alertCritical($incident, $actor);
            }

            return $incident->fresh();
        });
    }

    public const SOURCES = ['web' => 'web', 'app_operador' => 'app del operador', 'extranet' => 'extranet', 'sistema' => 'sistema'];

    /** `abierta → en_proceso`; sin responsable, se asigna al usuario actual. */
    public function take(Incident $incident, User $actor): Incident
    {
        if ($incident->status !== Incident::OPEN) {
            throw ApiException::rule('PROC-037', 'Solo una incidencia abierta pasa a en proceso.');
        }
        $incident->forceFill(['status' => Incident::IN_PROGRESS, 'assignee_id' => $incident->assignee_id ?? $actor->id])->save();
        $this->log($incident, 'estado', 'Abierta → En proceso', $actor);
        $this->audit->log('Incidencia', $incident->code, 'cambio_estado', 'Abierta', 'En proceso', actor: $actor);

        return $incident->fresh();
    }

    public function assign(Incident $incident, string $userId, User $actor): Incident
    {
        $this->assertEditable($incident);
        $user = $this->assignee($userId);
        $before = $incident->assignee?->name;
        $incident->forceFill(['assignee_id' => $user->id])->save();
        $this->log($incident, 'asignacion', "Responsable: {$user->name}", $actor);
        $this->audit->log('Incidencia', $incident->code, 'asignar', $before, $user->name, actor: $actor);
        $this->notify->push('incidencia.asignada', 'push', $user, "Incidencia {$incident->code} asignada", $incident->title, $this->data($incident));

        return $incident->fresh();
    }

    /** Reclasificar: recalcula el SLA desde el registro (§3.11) y escalar a crítica alerta a gerencia. */
    public function reclassify(Incident $incident, string $severity, string $type, User $actor): Incident
    {
        $this->assertEditable($incident);
        if (! isset(Incident::SLA_HOURS[$severity]) || ! in_array($type, Incident::TYPES, true)) {
            throw ApiException::invalid('severity', 'Severidad o tipo inválidos.');
        }
        $before = Incident::SEVERITY_LABELS[$incident->severity]." · {$incident->type}";
        $escalated = $severity === 'critica' && $incident->severity !== 'critica';
        $hours = Incident::SLA_HOURS[$severity];
        $incident->forceFill(['severity' => $severity, 'type' => $type, 'sla_hours' => $hours, 'sla_due_at' => $incident->created_at->copy()->addHours($hours),
            'sla_breached_at' => null])->save();
        $after = Incident::SEVERITY_LABELS[$severity]." · {$type}";
        $this->log($incident, 'reclasificacion', "{$before} → {$after} · SLA {$hours} h", $actor);
        $this->audit->log('Incidencia', $incident->code, 'reclasificar', $before, $after, actor: $actor);
        if ($escalated) {
            $this->alertCritical($incident, $actor);
        }

        return $incident->fresh();
    }

    public function resolve(Incident $incident, string $resolution, User $actor): Incident
    {
        if (! $incident->isActive()) {
            throw ApiException::rule('PROC-037', 'Solo se resuelve una incidencia abierta o en proceso.');
        }
        if (trim($resolution) === '') {
            throw ApiException::invalid('resolution', 'Describa la resolución.');
        }
        $before = Incident::STATUS_LABELS[$incident->status];
        $incident->forceFill(['status' => Incident::RESOLVED, 'resolution' => trim($resolution), 'resolved_at' => now(), 'resolved_by' => $actor->id])->save();
        $this->log($incident, 'resolucion', trim($resolution), $actor);
        $this->audit->log('Incidencia', $incident->code, 'resolver', $before, 'Resuelta', actor: $actor);

        return $incident->fresh();
    }

    /** OPS-BR-62. */
    public function close(Incident $incident, ?string $comment, User $actor): Incident
    {
        if ($incident->status === Incident::CLOSED) {
            throw ApiException::rule('PROC-037', 'La incidencia ya está cerrada.');
        }
        $comment = trim((string) $comment);
        if (in_array($incident->severity, ['alta', 'critica'], true) && ! $incident->resolution) {
            throw ApiException::unprocessable('OPS-BR-62', 'Una incidencia alta o crítica exige registrar la resolución antes de cerrar.');
        }
        if ($incident->severity === 'critica' && ! $incident->evidences()->exists()) {
            throw ApiException::unprocessable('OPS-BR-62', 'Una incidencia crítica exige al menos una evidencia antes de cerrar.');
        }
        if (! $incident->resolution && $comment === '') {
            throw ApiException::unprocessable('OPS-BR-62', 'Indique un comentario de cierre (se guarda como resolución).');
        }
        $before = Incident::STATUS_LABELS[$incident->status];
        $incident->forceFill([
            'status' => Incident::CLOSED, 'closed_at' => now(), 'closed_by' => $actor->id,
            'resolution' => $incident->resolution ?: $comment, 'resolved_at' => $incident->resolved_at ?? now(), 'resolved_by' => $incident->resolved_by ?? $actor->id,
        ])->save();
        $this->log($incident, 'estado', "{$before} → Cerrada".($comment !== '' ? " · {$comment}" : ''), $actor);
        $this->audit->log('Incidencia', $incident->code, 'cerrar', $before, 'Cerrada', actor: $actor);
        if ($incident->severity === 'critica') {
            foreach (CatalogRecipients::managers() as $manager) {
                $this->notify->push('incidencia.cerrada', 'push', $manager, "Incidencia crítica {$incident->code} cerrada", $incident->title, $this->data($incident));
            }
        }

        return $incident->fresh();
    }

    /** OPS-BR-63: motivo obligatorio, vuelve a `en_proceso`. */
    public function reopen(Incident $incident, string $reason, User $actor): Incident
    {
        if ($incident->status !== Incident::CLOSED) {
            throw ApiException::rule('PROC-037', 'Solo se reabre una incidencia cerrada.');
        }
        if (trim($reason) === '') {
            throw ApiException::invalid('reason', 'Indique el motivo de la reapertura (OPS-BR-63).');
        }
        $incident->forceFill(['status' => Incident::IN_PROGRESS, 'closed_at' => null, 'closed_by' => null])->save();
        $this->log($incident, 'estado', 'Cerrada → En proceso · '.trim($reason), $actor);
        $this->audit->log('Incidencia', $incident->code, 'reabrir', 'Cerrada', 'En proceso · '.trim($reason), actor: $actor);

        return $incident->fresh();
    }

    public function comment(Incident $incident, string $message, User $actor, string $visibility = 'proveedor'): IncidentLog
    {
        if (trim($message) === '') {
            throw ApiException::invalid('message', 'Escriba el comentario.');
        }

        return $this->log($incident, 'comentario', trim($message), $actor, $visibility);
    }

    /** IncidentSlaMonitor: marca SLA vencido y avisa al responsable y a gerencia. */
    public function monitorSla(): int
    {
        $count = 0;
        foreach (Incident::whereIn('status', Incident::ACTIVE)->whereNull('sla_breached_at')->where('sla_due_at', '<', now())->get() as $incident) {
            $incident->forceFill(['sla_breached_at' => now()])->save();
            $this->log($incident, 'alerta', "SLA vencido ({$incident->sla_hours} h)", null);
            $data = $this->data($incident);
            foreach ([$incident->assignee, ...CatalogRecipients::managers()->all()] as $user) {
                if ($user) {
                    $this->notify->push('incidencia.sla_vencido', 'portal', $user, "SLA vencido · {$incident->code}", $incident->title, $data);
                }
            }
            $count++;
        }

        return $count;
    }

    public function log(Incident $incident, string $kind, string $message, ?User $actor, string $visibility = 'proveedor'): IncidentLog
    {
        return IncidentLog::create(['incident_id' => $incident->id, 'user_id' => $actor?->id, 'kind' => $kind, 'message' => $message, 'visibility' => $visibility]);
    }

    /** OPS-BR-61: push + WhatsApp a gerencia y registro en bitácora. */
    private function alertCritical(Incident $incident, ?User $actor): void
    {
        $data = $this->data($incident);
        $managers = CatalogRecipients::managers();
        foreach ($managers as $manager) {
            $this->notify->push('incidencia.critica', 'push', $manager, "Incidencia CRÍTICA {$incident->code}", $incident->title, $data);
            $this->notify->push('incidencia.critica', 'whatsapp', $manager->phone ?? $manager->name, "Incidencia CRÍTICA {$incident->code}", $incident->title, $data);
        }
        $this->log($incident, 'alerta', 'Alerta crítica enviada a gerencia (push + WhatsApp): '.$managers->pluck('name')->implode(', '), null, 'interna');
    }

    private function assignee(string $id): User
    {
        $user = User::find($id);
        if (! $user || ! $user->isActive() || ! $user->hasAnyRole(OpsAccess::COORDINATOR_ROLES)) {
            throw ApiException::invalid('assignee_id', 'El responsable debe ser un usuario activo de Operaciones, Gerencia o Administración.');
        }

        return $user;
    }

    private function assertEditable(Incident $incident): void
    {
        if (! $incident->isActive()) {
            throw ApiException::rule('PROC-037', 'Asignar y reclasificar solo con la incidencia abierta o en proceso.');
        }
    }

    private function data(Incident $incident): array
    {
        return ['related_type' => 'ops_incident', 'related_id' => $incident->id, 'reservation_id' => $incident->reservation_id];
    }
}
