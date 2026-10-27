<?php

namespace App\Services\Ops;

use App\Exceptions\ApiException;
use App\Http\Resources\Ops\OpsPresenter;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\Passenger;
use App\Models\Iam\User;
use App\Models\Ops\Assignment;
use App\Models\Ops\Checkin;
use App\Models\Ops\Evidence;
use App\Models\Ops\Incident;
use App\Models\Ops\MobileDevice;
use App\Models\Ops\Order;
use App\Models\Ops\SyncAction;
use App\Services\AuditLogger;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * App del operador (PROC-056/058): ventana de asignaciones del recurso, acciones de campo idempotentes por
 * `client_action_id` (MOB-BR-01) y reglas de conflicto MOB-BR-05..11. Planificación = backend; hechos de campo = dispositivo.
 */
class OperatorApp
{
    public const TYPES = ['confirm', 'start', 'checkin', 'checkin_undo', 'checkin_all', 'evidence', 'incident', 'finish'];

    public const LABELS = [
        'confirm' => 'Confirmar asignación', 'start' => 'Iniciar servicio', 'checkin' => 'Check-in', 'checkin_undo' => 'Quitar check-in',
        'checkin_all' => 'Check-in grupal', 'evidence' => 'Evidencia', 'incident' => 'Incidencia', 'finish' => 'Finalizar servicio',
    ];

    public const MAX_BATCH = 50;

    public function __construct(
        private readonly AssignmentService $assignments,
        private readonly OrderService $orders,
        private readonly IncidentService $incidents,
        private readonly EvidenceService $evidences,
        private readonly OpsPresenter $present,
        private readonly CrmClock $clock,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /** Asignaciones del recurso en la ventana [-2 d, +14 d] (§12-13), de órdenes no canceladas. */
    public function window(string $type, Model $resource): Collection
    {
        $today = $this->clock->today();

        return Assignment::where(OpsAccess::column($type), $resource->id)
            ->whereBetween('service_date', [$today->copy()->subDays(2)->toDateString(), $today->copy()->addDays(14)->toDateString()])
            ->whereHas('order', fn ($q) => $q->where('status', '<>', Order::CANCELLED))
            ->with(['order.reservation.client', 'order.coordinator', 'guide', 'driver', 'vehicle', 'service.provider', 'checkins'])
            ->orderBy('service_date')->orderBy('service_time')->get();
    }

    /**
     * Delta sync: la ventana es pequeña, así que se devuelve completa (`full: true`); la app reemplaza su caché y las
     * asignaciones quitadas desaparecen sin tombstones explícitos.
     */
    public function bootstrap(string $type, Model $resource, ?MobileDevice $device): array
    {
        $now = now();
        $device?->forceFill(['last_seen_at' => $now, 'sync_cursor' => $now->toIso8601String()])->save();

        return [
            'cursor' => $now->toIso8601String(), 'full' => true, 'today' => $this->clock->today()->toDateString(),
            'resource' => $this->present->resourceProfile($type, $resource),
            'assignments' => $this->window($type, $resource)->map(fn (Assignment $a) => $this->present->mobileAssignment($a, $type))->values()->all(),
            'incidents' => $this->incidentsFor($resource, $type)->map(fn ($i) => $this->present->incidentRow($i))->values()->all(),
            'history' => SyncAction::where('resource_id', $resource->id)->orderByDesc('received_at')->limit(30)->get()
                ->map(fn (SyncAction $s) => ['client_action_id' => $s->id, 'type' => $s->action_type, 'label' => self::LABELS[$s->action_type] ?? $s->action_type,
                    'result' => $s->result, 'message' => $s->conflict_message, 'synced_at' => $s->received_at->toIso8601String(), 'offline' => $s->captured_offline])->all(),
            'last_sync_at' => $device?->last_sync_at?->toIso8601String(),
        ];
    }

    /** Incidencias de sus órdenes o reportadas por él (§2.1). */
    public function incidentsFor(Model $resource, string $type): Collection
    {
        $orderIds = Assignment::where(OpsAccess::column($type), $resource->id)->pluck('order_id')->unique();
        $user = OpsAccess::userOf($resource);

        return Incident::where(fn ($q) => $q->whereIn('order_id', $orderIds)->when($user, fn ($q) => $q->orWhere('reported_by', $user->id)))
            ->with('order:id,code')->orderByDesc('created_at')->limit(50)->get();
    }

    public function assignmentFor(string $type, Model $resource, string $id): Assignment
    {
        $assignment = Assignment::with(['order.reservation.client', 'order.coordinator', 'guide', 'driver', 'vehicle', 'service.provider', 'checkins'])->find($id);
        if (! $assignment || $assignment->{OpsAccess::column($type)} !== $resource->id) {
            throw ApiException::forbidden('OPS-BR-52', 'Solo puede ver y operar sus propias asignaciones.');
        }

        return $assignment;
    }

    /** Lote del outbox en orden de `occurred_at` (§8.4.4), máx. 50; cada acción en su transacción. */
    public function sync(array $actions, User $user, string $type, Model $resource, ?MobileDevice $device): array
    {
        if (count($actions) > self::MAX_BATCH) {
            throw ApiException::invalid('actions', 'Máximo '.self::MAX_BATCH.' acciones por lote.');
        }
        usort($actions, fn ($a, $b) => strcmp((string) ($a['occurred_at'] ?? ''), (string) ($b['occurred_at'] ?? '')));
        $results = [];
        foreach ($actions as $action) {
            try {
                $results[] = $this->apply($action, $user, $type, $resource, $device);
            } catch (ApiException $e) {
                // 403 de otra asignación o validación: se informa por acción; el resto del lote continúa.
                $results[] = ['client_action_id' => $action['client_action_id'] ?? null, 'result' => 'rejected', 'conflict_rule' => $e->errorCode, 'message' => $e->getMessage()];
            }
        }
        $device?->forceFill(['last_sync_at' => now()])->save();

        return $results;
    }

    /**
     * Una acción `{client_action_id, type, assignment_id, occurred_at, offline, attempts, payload}` →
     * `{result: applied|duplicate|conflict_resolved|rejected, conflict_rule?, message?, assignment}`.
     */
    public function apply(array $action, User $user, string $type, Model $resource, ?MobileDevice $device): array
    {
        $id = (string) ($action['client_action_id'] ?? '');
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) || ! in_array($action['type'] ?? null, self::TYPES, true)) {
            throw ApiException::invalid('client_action_id', 'Acción inválida: client_action_id (UUID) y type son obligatorios.');
        }
        if ($existing = SyncAction::find($id)) {
            return ['result' => 'duplicate'] + $existing->response;
        }
        $assignment = Assignment::find($action['assignment_id'] ?? null) ?? throw ApiException::notFound();
        $column = OpsAccess::column($type);
        if ($assignment->{$column} !== $resource->id) {
            $wasMine = SyncAction::where('assignment_id', $assignment->id)->where('resource_id', $resource->id)->exists();
            if (! $wasMine) {
                throw ApiException::forbidden('OPS-BR-52', 'Solo puede operar sus propias asignaciones.');
            }
        }
        $occurred = ! empty($action['occurred_at']) ? Carbon::parse($action['occurred_at']) : now();
        if ($occurred->isAfter(now()->addMinutes(5))) {
            $occurred = now();
        }
        $offline = (bool) ($action['offline'] ?? false);
        $payload = (array) ($action['payload'] ?? []);

        $outcome = ['result' => 'applied', 'conflict_rule' => null, 'message' => null];
        try {
            DB::transaction(function () use (&$outcome, $action, $assignment, $user, $resource, $column, $occurred, $offline, $payload, $device, $id) {
                $assignment = Assignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
                // MOB-BR-10: liberada o reasignada a otro recurso → rechazada (la app revierte).
                if ($assignment->{$column} !== $resource->id) {
                    throw ApiException::rule('MOB-BR-10', 'La asignación fue liberada o reasignada a otro recurso.');
                }
                $outcome = $this->handle($action['type'], $assignment, $user, $occurred, $offline, $payload, $device, $id) + $outcome;
                $this->audit->log('App operador', $assignment->service_label, $action['type'], null,
                    (self::LABELS[$action['type']] ?? $action['type']).' · '.($resource->code ?? '').($offline ? ' · offline '.$occurred->toIso8601String() : '')
                    .($outcome['result'] === 'conflict_resolved' ? " · {$outcome['conflict_rule']}" : ''), actor: $user);
            });
        } catch (ApiException $e) {
            if ($e->status === 403 || $e->status === 404) {
                throw $e;
            }
            $outcome = ['result' => 'rejected', 'conflict_rule' => $e->errorCode, 'message' => $e->getMessage()];
        }

        $fresh = Assignment::with(['order.reservation.client', 'order.coordinator', 'guide', 'driver', 'vehicle', 'service.provider', 'checkins'])->find($assignment->id);
        $response = ['client_action_id' => $id] + $outcome + ['assignment' => $this->present->mobileAssignment($fresh, $type)];
        SyncAction::create([
            'id' => $id, 'user_id' => $user->id, 'device_id' => $device?->id, 'resource_type' => $type, 'resource_id' => $resource->id,
            'action_type' => $action['type'], 'assignment_id' => $assignment->id,
            'coalesce_key' => mb_substr("{$action['type']}:{$assignment->order_id}:{$assignment->reservation_service_id}:".($payload['passenger_id'] ?? ''), 0, 120),
            'payload' => array_diff_key($payload, ['content_base64' => true, 'photo' => true]), 'occurred_at' => $occurred, 'captured_offline' => $offline, 'received_at' => now(),
            'attempts' => max(1, (int) ($action['attempts'] ?? 1)), 'result' => $outcome['result'], 'conflict_rule' => $outcome['conflict_rule'],
            'conflict_message' => $outcome['message'], 'response' => $response,
        ]);

        return $response;
    }

    /** @return array{result?: string, conflict_rule?: ?string, message?: ?string} */
    private function handle(string $type, Assignment $assignment, User $user, Carbon $occurred, bool $offline, array $payload, ?MobileDevice $device, string $actionId): array
    {
        $order = Order::findOrFail($assignment->order_id);
        $service = BookedService::find($assignment->reservation_service_id);

        switch ($type) {
            case 'confirm':
                // MOB-BR-05: ya confirmada en web → se descarta el duplicado sin error.
                if (in_array($assignment->status, Assignment::CONFIRMED_OR_LATER, true)) {
                    return $this->resolved('MOB-BR-05', 'La asignación ya estaba confirmada; se descartó el duplicado.');
                }
                $this->assignments->confirm($assignment, $user, 'app');

                return [];

            case 'start':
                if (in_array($assignment->status, [Assignment::IN_PROGRESS, Assignment::COMPLETED], true)) {
                    // MOB-BR-07: se conserva la hora real de inicio más temprana.
                    if ($assignment->started_at && $occurred->lt($assignment->started_at)) {
                        $assignment->forceFill(['started_at' => $occurred])->save();
                    }

                    return $this->resolved('MOB-BR-07', 'El servicio ya estaba iniciado; se conserva la hora real de inicio.');
                }
                if ($assignment->status !== Assignment::CONFIRMED) {
                    throw ApiException::rule('MOB-BR-10', 'Confirme la asignación antes de iniciar el servicio.');
                }
                $assignment->forceFill(['status' => Assignment::IN_PROGRESS, 'started_at' => $occurred])->save();
                if ($service && $service->status === BookedService::CONFIRMED) {
                    $service->forceFill(['status' => BookedService::IN_PROGRESS])->save();
                }
                if (in_array($order->status, [Order::PLANNED, ...Order::RECOMPUTABLE], true)) {
                    $this->orders->moveToExecution($order, $user, 'app');
                }
                $this->pushCoordinator($order, 'servicio.iniciado', "Servicio iniciado · {$order->code}", "{$assignment->service_label} · {$user->name}");
                $planned = $assignment->service_time ? substr((string) $assignment->service_time, 0, 5) : null;
                if ($offline && $planned && $occurred->format('H:i') !== $planned) {
                    return $this->resolved('MOB-BR-07', "Inicio registrado a las {$occurred->format('H:i')} (planificado {$planned}): se conserva la hora real.");
                }

                return [];

            case 'checkin':
            case 'checkin_undo':
                $this->assertInProgress($assignment);
                $passenger = Passenger::where('reservation_id', $order->reservation_id)->find($payload['passenger_id'] ?? null)
                    ?? throw ApiException::rule('MOB-BR-10', 'El pasajero no pertenece a la reserva.');
                $active = Checkin::where('assignment_id', $assignment->id)->where('passenger_id', $passenger->id)->whereNull('undone_at')->first();
                if ($type === 'checkin_undo' || ! empty($payload['undo'])) {
                    $active?->forceFill(['undone_at' => $occurred, 'undone_by' => $user->id])->save();

                    return $active ? [] : $this->resolved('MOB-BR-06', 'El pasajero no tenía check-in.');
                }
                if ($active) {
                    // MOB-BR-06: idempotencia pasajero + servicio; se conserva la marca más temprana.
                    if ($occurred->lt($active->checked_at)) {
                        $active->forceFill(['checked_at' => $occurred])->save();
                        $this->refreshFirstCheckin($assignment);

                        return $this->resolved('MOB-BR-06', "Check-in de {$passenger->full_name} ya existía: se conserva la marca más temprana ({$occurred->format('H:i')}).");
                    }

                    return $this->resolved('MOB-BR-06', "Check-in de {$passenger->full_name} ya existía: se conserva la marca más temprana.");
                }
                Checkin::create(['assignment_id' => $assignment->id, 'passenger_id' => $passenger->id, 'checked_at' => $occurred, 'source' => 'app',
                    'client_action_id' => $actionId, 'device_id' => $device?->id, 'checked_by' => $user->id]);
                $this->refreshFirstCheckin($assignment);

                return [];

            case 'checkin_all':
                $this->assertInProgress($assignment);
                $done = Checkin::where('assignment_id', $assignment->id)->whereNull('undone_at')->pluck('passenger_id')->all();
                $missing = Passenger::where('reservation_id', $order->reservation_id)->whereNotIn('id', $done)->get();
                foreach ($missing as $passenger) {
                    Checkin::create(['assignment_id' => $assignment->id, 'passenger_id' => $passenger->id, 'checked_at' => $occurred, 'source' => 'app',
                        'client_action_id' => $actionId, 'device_id' => $device?->id, 'checked_by' => $user->id]);
                }
                $this->refreshFirstCheckin($assignment);

                return $missing->isEmpty() ? $this->resolved('MOB-BR-06', 'Todos los pasajeros ya tenían check-in.') : [];

            case 'evidence':
                if (! in_array($assignment->status, [Assignment::IN_PROGRESS, Assignment::COMPLETED], true)) {
                    throw ApiException::rule('MOB-BR-10', 'Inicie el servicio antes de registrar evidencias.');
                }
                [, $duplicate] = $this->evidences->store([
                    'file_name' => $payload['file_name'] ?? 'evidencia.jpg', 'mime_type' => $payload['mime_type'] ?? 'image/jpeg',
                    'content_base64' => $payload['content_base64'] ?? '', 'sha256' => $payload['sha256'] ?? null, 'captured_at' => $occurred->toIso8601String(),
                ], $order, $service, null, $user, 'app_operador', $offline, $actionId);

                // MOB-BR-09: mismo hash → se asocia la existente.
                return $duplicate ? $this->resolved('MOB-BR-09', 'La foto ya estaba registrada (mismo hash): se asoció la existente.') : [];

            case 'incident':
                $incident = $this->incidents->create([
                    'order_id' => $order->id, 'reservation_service_id' => $service?->id, 'type' => $payload['type'] ?? 'Otro',
                    'severity' => $payload['severity'] ?? 'media', 'title' => $payload['title'] ?? '', 'description' => $payload['description'] ?? '',
                ], $user, 'app_operador', $actionId);
                // "Adjuntar foto" del formulario de la app: queda como evidencia de la incidencia.
                if (! empty($payload['photo']['content_base64'])) {
                    $this->evidences->store([
                        'file_name' => $payload['photo']['file_name'] ?? 'incidencia.jpg', 'mime_type' => $payload['photo']['mime_type'] ?? 'image/jpeg',
                        'content_base64' => $payload['photo']['content_base64'], 'sha256' => $payload['photo']['sha256'] ?? null, 'captured_at' => $occurred->toIso8601String(),
                    ], $order, $service, $incident, $user, 'app_operador', $offline, $actionId);
                }

                return $incident->related_incident_id
                    ? $this->resolved('MOB-BR-11', "Registrada como {$incident->code}, vinculada a una incidencia similar del coordinador para revisión.")
                    : ['message' => "Incidencia {$incident->code} registrada."];

            case 'finish':
                if ($assignment->status === Assignment::COMPLETED) {
                    return $this->resolved('MOB-BR-08', 'El servicio ya estaba finalizado.');
                }
                $this->assertInProgress($assignment);
                $checkins = Checkin::where('assignment_id', $assignment->id)->whereNull('undone_at')->pluck('passenger_id')->all();
                $evidence = Evidence::where('reservation_service_id', $assignment->reservation_service_id)->whereNull('removed_at')->exists();
                if (! $checkins || ! $evidence) {
                    throw ApiException::rule('OPS-BR-51', 'Para finalizar se requiere al menos un check-in y una evidencia del servicio.');
                }
                $noShow = Passenger::where('reservation_id', $order->reservation_id)->whereNotIn('id', $checkins)->pluck('id')->all();
                $assignment->forceFill(['status' => Assignment::COMPLETED, 'finished_at' => $occurred, 'no_show_passenger_ids' => $noShow ?: null])->save();
                if ($service && in_array($service->status, [BookedService::CONFIRMED, BookedService::IN_PROGRESS], true)) {
                    $service->forceFill(['status' => BookedService::COMPLETED])->save();
                }
                $this->pushCoordinator($order, 'servicio.finalizado', "Servicio finalizado · {$order->code}",
                    "{$assignment->service_label}".($noShow ? ' · '.count($noShow).' no-show' : ''));

                return $noShow ? ['message' => count($noShow).' pasajero(s) sin check-in quedan como no-show.'] : [];
        }

        return [];
    }

    private function resolved(string $rule, string $message): array
    {
        return ['result' => 'conflict_resolved', 'conflict_rule' => $rule, 'message' => $message];
    }

    /** OPS-BR-50: check-in, evidencia y fin solo con el servicio `en_curso`. */
    private function assertInProgress(Assignment $assignment): void
    {
        if ($assignment->status !== Assignment::IN_PROGRESS) {
            throw ApiException::rule('OPS-BR-50', 'El check-in y el fin del servicio se habilitan con el servicio en curso.');
        }
    }

    private function refreshFirstCheckin(Assignment $assignment): void
    {
        $first = Checkin::where('assignment_id', $assignment->id)->whereNull('undone_at')->min('checked_at');
        $assignment->forceFill(['first_checkin_at' => $first])->save();
    }

    private function pushCoordinator(Order $order, string $event, string $subject, string $body): void
    {
        if ($order->coordinator) {
            $this->notify->push($event, 'push', $order->coordinator, $subject, $body, ['related_type' => 'ops_order', 'related_id' => $order->id]);
        }
    }

    public function registerDevice(User $user, array $data): MobileDevice
    {
        $device = MobileDevice::find($data['id']) ?? new MobileDevice(['id' => $data['id']]);
        if ($device->exists && $device->user_id !== $user->id) {
            throw ApiException::forbidden('MOB-BR-01', 'El dispositivo pertenece a otro usuario.');
        }
        if (! empty($data['expo_push_token'])) {
            MobileDevice::where('expo_push_token', $data['expo_push_token'])->where('id', '<>', $data['id'])->update(['expo_push_token' => null]);
        }
        $device->fill([
            'user_id' => $user->id, 'app' => $data['app'], 'platform' => $data['platform'], 'app_version' => $data['app_version'],
            'expo_push_token' => $data['expo_push_token'] ?? null, 'last_seen_at' => now(), 'revoked_at' => null,
        ])->save();

        return $device->fresh();
    }
}
