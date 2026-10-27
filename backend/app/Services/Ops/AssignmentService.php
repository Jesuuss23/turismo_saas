<?php

namespace App\Services\Ops;

use App\Exceptions\ApiException;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\Passenger;
use App\Models\Catalog\Departure;
use App\Models\Iam\User;
use App\Models\Ops\Assignment;
use App\Models\Ops\Driver;
use App\Models\Ops\Guide;
use App\Models\Ops\Order;
use App\Models\Ops\Vehicle;
use App\Services\AuditLogger;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Asignación de guías, conductores y vehículos (PROC-033, §4.2): validación de cruces, estado, idioma, licencia, SOAT
 * y capacidad (OPS-BR-20..28), lock Redis por recurso + fecha, confirmación y liberación (OPS-BR-29).
 */
class AssignmentService
{
    /** OPS-BR-21: ventana de cruce (§12-3: fija en 6 h hasta parametrizarla por tenant). */
    public const WINDOW_MINUTES = 360;

    public const NATIONALITY_LANGUAGES = [
        'alemana' => 'DE', 'alemán' => 'DE', 'austriaca' => 'DE', 'suiza' => 'DE', 'francesa' => 'FR', 'belga' => 'FR',
        'brasileña' => 'PT', 'portuguesa' => 'PT', 'estadounidense' => 'EN', 'norteamericana' => 'EN', 'británica' => 'EN',
        'inglesa' => 'EN', 'canadiense' => 'EN', 'australiana' => 'EN', 'irlandesa' => 'EN', 'neozelandesa' => 'EN',
    ];

    public function __construct(
        private readonly OrderService $orders,
        private readonly CrmClock $clock,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /**
     * Dry-run del modal (validación en vivo).
     *
     * @return array{blocks: list<array{code: string, text: string}>, warnings: list<array{code: string, text: string}>}
     */
    public function validate(Assignment $assignment, ?string $guideId, ?string $driverId, ?string $vehicleId): array
    {
        $blocks = [];
        $warnings = [];
        $service = BookedService::find($assignment->reservation_service_id);
        if (! $guideId && ! $driverId && ! $vehicleId) {
            $blocks[] = ['code' => 'OPS-BR-20', 'text' => 'Seleccione al menos un recurso (guía, conductor o vehículo).'];
        }
        if (! $service || ! $service->isActive()) {
            $blocks[] = ['code' => 'OPS-BR-20', 'text' => 'El servicio está cancelado o cerrado: no admite asignación.'];
        }
        $date = $assignment->service_date->toDateString();
        $time = $assignment->service_time ? substr((string) $assignment->service_time, 0, 5) : null;
        $order = Order::find($assignment->order_id);
        $pax = Passenger::where('reservation_id', $order?->reservation_id)->count() ?: (int) $service?->pax;

        $resources = array_filter([
            'guide' => $guideId ? Guide::find($guideId) : null,
            'driver' => $driverId ? Driver::find($driverId) : null,
            'vehicle' => $vehicleId ? Vehicle::find($vehicleId) : null,
        ]);
        foreach (['guide' => $guideId, 'driver' => $driverId, 'vehicle' => $vehicleId] as $type => $id) {
            if ($id && ! isset($resources[$type])) {
                $blocks[] = ['code' => 'OPS-BR-20', 'text' => 'Recurso inexistente.'];
            }
        }

        foreach ($resources as $type => $resource) {
            $name = $resource->label();
            // OPS-BR-24 / OPS-BR-27: estado del recurso.
            if ($resource->status === 'vacaciones') {
                $blocks[] = ['code' => 'OPS-BR-24', 'text' => "{$name} está de vacaciones."];
            } elseif ($resource->status === 'descanso') {
                $warnings[] = ['code' => 'OPS-BR-24', 'text' => "{$name} está en descanso."];
            } elseif ($resource->status === 'mantenimiento') {
                $blocks[] = ['code' => 'OPS-BR-27', 'text' => "{$name} está en mantenimiento."];
            }
            // OPS-BR-21 / 22: cruces con otras ocupaciones del mismo día.
            foreach ($this->occupations($type, $resource->id, $date, $date, $assignment->id) as $occ) {
                $diff = $this->minutesBetween($time, $occ['time']);
                $sameOrder = $occ['order_id'] !== null && $occ['order_id'] === $assignment->order_id;
                $where = $occ['source_type'] === 'departure' ? "la salida {$occ['label']}" : "{$occ['label']} ({$occ['order_code']})";
                if ($diff !== null && $diff < self::WINDOW_MINUTES && ! $sameOrder) {
                    $blocks[] = ['code' => 'OPS-BR-21', 'text' => "{$name} ya está asignado a {$where} a las ".($occ['time'] ?? '—').' (menos de 6 h).'];
                } elseif ($diff !== null && $diff < self::WINDOW_MINUTES) {
                    $warnings[] = ['code' => 'OPS-BR-22', 'text' => "{$name} tiene otro servicio de la misma orden a menos de 6 h ({$occ['label']}): verifique el encadenamiento."];
                } else {
                    $warnings[] = ['code' => 'OPS-BR-22', 'text' => "{$name} tiene otro servicio ese día ({$where}".($occ['time'] ? " a las {$occ['time']}" : '').'): verifique tiempos de retorno.'];
                }
            }
        }

        if ($guide = $resources['guide'] ?? null) {
            $missing = array_values(array_diff($this->requiredLanguages($order?->reservation_id), (array) $guide->languages));
            if ($missing) {
                $warnings[] = ['code' => 'OPS-BR-25', 'text' => "{$guide->full_name} no cubre idioma(s) de los pasajeros: ".implode(', ', $missing).'.'];
            }
        }
        $soon = $this->clock->today()->addDays(30)->toDateString();
        if ($driver = $resources['driver'] ?? null) {
            $expires = $driver->license_expires_on->toDateString();
            if ($expires < $date) {
                $blocks[] = ['code' => 'OPS-BR-26', 'text' => "La licencia de {$driver->full_name} vence el {$expires}, antes del servicio."];
            } elseif ($expires <= $soon) {
                $warnings[] = ['code' => 'OPS-BR-26', 'text' => "La licencia de {$driver->full_name} vence el {$expires} (≤ 30 días)."];
            }
        }
        if ($vehicle = $resources['vehicle'] ?? null) {
            if ($vehicle->capacity < $pax) {
                $blocks[] = ['code' => 'OPS-BR-27', 'text' => "{$vehicle->label()} tiene capacidad {$vehicle->capacity} para {$pax} pasajero(s)."];
            }
            $soat = $vehicle->soat_expires_on->toDateString();
            if ($soat < $date) {
                $blocks[] = ['code' => 'OPS-BR-27', 'text' => "El SOAT de {$vehicle->plate} vence el {$soat}, antes del servicio."];
            } elseif ($soat <= $soon) {
                $warnings[] = ['code' => 'OPS-BR-27', 'text' => "El SOAT de {$vehicle->plate} vence el {$soat} (≤ 30 días)."];
            }
        }

        return ['blocks' => array_values(array_unique($blocks, SORT_REGULAR)), 'warnings' => array_values(array_unique($warnings, SORT_REGULAR))];
    }

    /** Asignar / reasignar con lock Redis por recurso + fecha (T-08) y advertencias asumidas (OPS-BR-28). */
    public function assign(Assignment $assignment, array $data, User $actor): Assignment
    {
        $ids = ['guide' => $data['guide_id'] ?? null, 'driver' => $data['driver_id'] ?? null, 'vehicle' => $data['vehicle_id'] ?? null];
        $keys = collect($ids)->filter()->map(fn ($id, $type) => "ops:resource:{$type}:{$id}:{$assignment->service_date->toDateString()}")->sort()->values();
        $locks = [];
        try {
            foreach ($keys as $key) {
                $lock = Cache::lock($key, 15);
                if (! $lock->get()) {
                    throw ApiException::conflict('RESOURCE_LOCKED', 'Otro usuario está asignando este recurso para la misma fecha; intente de nuevo.');
                }
                $locks[] = $lock;
            }

            return DB::transaction(function () use ($assignment, $ids, $data, $actor) {
                $assignment = Assignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
                $order = Order::findOrFail($assignment->order_id);
                $this->orders->assertActive($order);
                if ($assignment->status === Assignment::COMPLETED || $assignment->status === Assignment::IN_PROGRESS) {
                    throw ApiException::rule('OPS-BR-20', 'La asignación ya está en curso o completada.');
                }
                $check = $this->validate($assignment, $ids['guide'], $ids['driver'], $ids['vehicle']);
                if ($check['blocks']) {
                    throw ApiException::rule($check['blocks'][0]['code'], $check['blocks'][0]['text'], $check);
                }
                if ($check['warnings'] && empty($data['ack_warnings'])) {
                    throw ApiException::unprocessable('OPS-BR-28', 'Hay advertencias: marque "Asumo las advertencias" para continuar.', $check);
                }
                $before = $this->describe($assignment);
                $previous = ['guide_id' => $assignment->guide_id, 'driver_id' => $assignment->driver_id];
                $assignment->forceFill([
                    'guide_id' => $ids['guide'], 'driver_id' => $ids['driver'], 'vehicle_id' => $ids['vehicle'], 'status' => Assignment::ASSIGNED,
                    'confirmed_at' => null, 'confirmed_via' => null,
                    'warnings_ack' => $check['warnings'] ? ['items' => $check['warnings'], 'user_id' => $actor->id, 'user' => $actor->name, 'at' => now()->toIso8601String()] : null,
                ])->save();
                $assignment->load(['guide', 'driver', 'vehicle']);
                $this->audit->log('Asignación', "{$order->code} · {$assignment->service_label}", 'asignar', $before,
                    $this->describe($assignment).($check['warnings'] ? ' · '.count($check['warnings']).' advertencia(s) asumidas' : ''), actor: $actor);
                $notify = (array) ($data['notify'] ?? ['push' => true]);
                foreach (['guide' => $assignment->guide, 'driver' => $assignment->driver] as $type => $resource) {
                    $user = OpsAccess::userOf($resource);
                    if (! $user || $previous["{$type}_id"] === $resource->id) {
                        continue;
                    }
                    $msg = "{$assignment->service_label} · {$assignment->service_date->toDateString()}".($assignment->service_time ? ' '.substr((string) $assignment->service_time, 0, 5) : '');
                    $link = ['related_type' => 'ops_assignment', 'related_id' => $assignment->id];
                    if (! empty($notify['push'])) {
                        $this->notify->push('asignacion.nueva', 'push', $user, "Nueva asignación · {$order->code}", $msg, $link);
                    }
                    if (! empty($notify['whatsapp']) && $resource->phone) {
                        $this->notify->push('asignacion.nueva', 'whatsapp', $resource->phone, "Nueva asignación · {$order->code}", $msg, $link);
                    }
                }
                $this->orders->recompute($order);

                return $assignment->fresh(['guide', 'driver', 'vehicle']);
            });
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    /** "Registrar confirmación del recurso" (web) o acción móvil `confirm`. */
    public function confirm(Assignment $assignment, User $actor, string $via = 'web'): Assignment
    {
        $order = Order::findOrFail($assignment->order_id);
        $this->orders->assertActive($order);
        if (! in_array($assignment->status, [Assignment::ASSIGNED, Assignment::PENDING], true) || ! $assignment->hasResource()) {
            throw ApiException::rule('OPS-BR-05', 'Solo se confirma una asignación con recursos que aún no fue confirmada.');
        }
        $assignment->forceFill(['status' => Assignment::CONFIRMED, 'confirmed_at' => now(), 'confirmed_via' => $via])->save();
        $this->audit->log('Asignación', "{$order->code} · {$assignment->service_label}", 'confirmar', 'Asignada', 'Confirmada · '.($via === 'app' ? 'app del operador' : 'registrada en web'), actor: $actor);
        if ($via === 'app' && $order->coordinator) {
            $this->notify->push('asignacion.confirmada', 'push', $order->coordinator, "Asignación confirmada · {$order->code}", "{$assignment->service_label} · {$actor->name}",
                ['related_type' => 'ops_order', 'related_id' => $order->id]);
        }
        $this->orders->recompute($order);

        return $assignment->fresh();
    }

    /** OPS-BR-29: libera y avisa por WhatsApp a cada recurso. */
    public function release(Assignment $assignment, User $actor): Assignment
    {
        $order = Order::findOrFail($assignment->order_id);
        $this->orders->assertActive($order);
        if (in_array($assignment->status, [Assignment::PENDING, Assignment::COMPLETED], true)) {
            throw ApiException::rule('OPS-BR-29', 'La asignación no tiene recursos que liberar o ya se completó.');
        }
        $assignment->load(['guide', 'driver', 'vehicle']);
        $before = $this->describe($assignment);
        foreach ([$assignment->guide, $assignment->driver] as $resource) {
            $phone = $resource?->phone;
            if ($phone) {
                $this->notify->push('asignacion.liberada', 'whatsapp', $phone, "Asignación liberada · {$order->code}",
                    "{$assignment->service_label} · {$assignment->service_date->toDateString()}", ['related_type' => 'ops_assignment', 'related_id' => $assignment->id]);
            }
        }
        $assignment->forceFill([
            'guide_id' => null, 'driver_id' => null, 'vehicle_id' => null, 'status' => Assignment::PENDING, 'confirmed_at' => null,
            'confirmed_via' => null, 'started_at' => null, 'warnings_ack' => null,
        ])->save();
        $this->audit->log('Asignación', "{$order->code} · {$assignment->service_label}", 'liberar', $before, 'Sin recursos', actor: $actor);
        $this->orders->recompute($order);

        return $assignment->fresh();
    }

    /**
     * Vista `ops_resource_occupations` (§3.7): asignaciones de órdenes no canceladas (≠ completada, OPS-BR-23) y salidas
     * programadas (guía y vehículo).
     *
     * @return Collection<int, array{source_type: string, source_id: string, order_id: ?string, order_code: ?string, date: string, time: ?string, label: string}>
     */
    public function occupations(string $type, string $resourceId, string $from, string $to, ?string $exceptAssignment = null): Collection
    {
        $column = "{$type}_id";
        $assignments = Assignment::query()->where($column, $resourceId)->where('status', '<>', Assignment::COMPLETED)
            ->whereBetween('service_date', [$from, $to])->when($exceptAssignment, fn ($q) => $q->where('id', '<>', $exceptAssignment))
            ->whereHas('order', fn ($q) => $q->where('status', '<>', Order::CANCELLED))->with('order:id,code')->get()
            ->map(fn (Assignment $a) => [
                'source_type' => 'assignment', 'source_id' => $a->id, 'order_id' => $a->order_id, 'order_code' => $a->order?->code,
                'date' => $a->service_date->toDateString(), 'time' => $a->service_time ? substr((string) $a->service_time, 0, 5) : null,
                'label' => $a->service_label, 'status' => $a->status,
            ]);
        $departures = in_array($type, ['guide', 'vehicle'], true)
            ? Departure::where($column, $resourceId)->where('status', '<>', 'cancelada')->whereBetween('departure_date', [$from, $to])->with('product:id,ref,name')->get()
                ->map(fn (Departure $d) => [
                    'source_type' => 'departure', 'source_id' => $d->id, 'order_id' => null, 'order_code' => null,
                    'date' => $d->departure_date->toDateString(), 'time' => substr((string) $d->departure_time, 0, 5),
                    'label' => trim("{$d->ref} · ".($d->product?->name ?? '')), 'status' => $d->status,
                ])
            : collect();

        return $assignments->concat($departures)->sortBy(fn ($o) => $o['date'].($o['time'] ?? '99'))->values();
    }

    /** Licencias, carnés y SOAT vencidos o por vencer (≤ 30 días). */
    public function documentAlerts(): array
    {
        $today = $this->clock->today()->toDateString();
        $limit = $this->clock->today()->addDays(30)->toDateString();
        $alerts = [];
        $push = function (string $type, Model $r, string $label, string $date) use (&$alerts, $today) {
            $alerts[] = ['type' => $type, 'id' => $r->id, 'code' => $r->code, 'name' => $r instanceof Vehicle ? $r->label() : $r->full_name,
                'document' => $label, 'expires_on' => $date, 'expired' => $date < $today];
        };
        foreach (Guide::whereNotNull('license_expires_on')->where('license_expires_on', '<=', $limit)->get() as $g) {
            $push('guide', $g, 'Carné de guía', $g->license_expires_on->toDateString());
        }
        foreach (Driver::where('license_expires_on', '<=', $limit)->get() as $d) {
            $push('driver', $d, 'Licencia de conducir', $d->license_expires_on->toDateString());
        }
        foreach (Vehicle::where('soat_expires_on', '<=', $limit)->get() as $v) {
            $push('vehicle', $v, 'SOAT', $v->soat_expires_on->toDateString());
        }

        return collect($alerts)->sortBy('expires_on')->values()->all();
    }

    /** OPS-BR-25 (§12-4): idiomas derivados de la nacionalidad y de las notas ("alemán"). */
    public function requiredLanguages(?string $reservationId): array
    {
        $languages = [];
        foreach (Passenger::where('reservation_id', $reservationId)->get(['nationality', 'diet_notes']) as $p) {
            $text = mb_strtolower(($p->nationality ?? '').' '.($p->diet_notes ?? ''));
            foreach (self::NATIONALITY_LANGUAGES as $needle => $lang) {
                if (str_contains($text, $needle)) {
                    $languages[$lang] = true;
                }
            }
        }

        return array_keys($languages);
    }

    private function minutesBetween(?string $a, ?string $b): ?int
    {
        if (! $a || ! $b) {
            return null;
        }

        return (int) abs(Carbon::createFromFormat('H:i', $a)->diffInMinutes(Carbon::createFromFormat('H:i', $b)));
    }

    private function describe(Assignment $a): string
    {
        $parts = array_filter([$a->guide?->full_name, $a->driver?->full_name, $a->vehicle?->plate]);

        return $parts ? implode(' · ', $parts) : 'Sin recursos';
    }
}
