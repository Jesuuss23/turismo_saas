<?php

namespace App\Services\Catalog;

use App\Exceptions\ApiException;
use App\Models\Catalog\Departure;
use App\Models\Catalog\Hold;
use App\Models\Catalog\Product;
use App\Models\Catalog\Provider;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Catalog\Links\CatalogLinks;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use App\Services\SequenceService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Motor de disponibilidad (PROC-020, §4.4–4.5, CAT-BR-20..25, PRD-BR-012). Único servicio que
 * mueve cupos: cotizador, reservas, B2B y e-commerce lo invocan. Toda escritura sobre una salida
 * va bajo `Cache::lock("catalog:departure:{id}")` + transacción; el CHECK `sold <= capacity` es la última barrera.
 */
class AvailabilityService
{
    public const MAX_CAPACITY = 60;

    public const MIN_HOLD_MINUTES = 5;

    public const DEPARTURE_FIELDS = ['departure_date', 'departure_time', 'capacity', 'status', 'provider_id', 'guide_id', 'vehicle_id', 'meeting_point', 'return_point', 'notes'];

    public function __construct(
        private readonly CrmClock $clock,
        private readonly TenantContext $tenant,
        private readonly CatalogLinks $links,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /** Cupos retenidos por holds vigentes (§12-15: los vencidos no procesados ya no restan). */
    public function held(Departure $departure): int
    {
        return (int) Hold::where('departure_id', $departure->id)->where('status', 'hold')->where('expires_at', '>', now())->sum('quantity');
    }

    public function available(Departure $departure, ?int $held = null): int
    {
        if (in_array($departure->status, ['cancelada', 'cerrada'], true)) {
            return 0;
        }

        return max(0, $departure->capacity - $departure->sold - ($held ?? $this->held($departure)));
    }

    /** Cupos retenidos de varias salidas en una consulta. @return array<string, int> */
    public function heldMany(Collection $departureIds): array
    {
        return Hold::whereIn('departure_id', $departureIds)->where('status', 'hold')->where('expires_at', '>', now())
            ->groupBy('departure_id')->selectRaw('departure_id, sum(quantity) as q')->pluck('q', 'departure_id')->map(fn ($q) => (int) $q)->all();
    }

    public function holdMinutes(): int
    {
        return max(self::MIN_HOLD_MINUTES, (int) data_get($this->tenant->tenant()?->settings, 'catalog.hold_minutes', 30));
    }

    /** @return array{departure: Departure, warnings: list<string>} */
    public function createDeparture(Product $product, array $data, User $actor, bool $fromGlobal = false): array
    {
        if ($fromGlobal && in_array($product->status, [Product::INACTIVE, Product::ARCHIVED], true)) {
            throw ApiException::unprocessable('CAT-BR-20', 'No se pueden programar salidas de un producto inactivo o archivado.');
        }
        $data = Arr::only($data, self::DEPARTURE_FIELDS) + ['status' => 'abierta'];
        if ((string) $data['departure_date'] < $this->clock->today()->toDateString()) {
            throw ApiException::invalid('departure_date', 'La fecha de salida no puede ser pasada.');
        }
        if (! in_array($data['status'], ['abierta', 'cerrada', 'cancelada'], true)) {
            throw ApiException::invalid('status', 'Estado inicial inválido.');
        }
        $warnings = $this->validateDeparture($data);

        return DB::transaction(function () use ($product, $data, $warnings, $actor) {
            $departure = Departure::create($data + ['ref' => $this->sequences->next('catalog_departure'), 'product_id' => $product->id, 'sold' => 0]);
            $this->audit->log('Salida', $departure->ref, 'crear', null, "{$product->ref} · {$departure->departure_date->toDateString()} {$departure->departure_time} · capacidad {$departure->capacity} · {$departure->status}", actor: $actor);

            return ['departure' => $departure, 'warnings' => $warnings];
        });
    }

    /** Editar salida (CAT-BR-20, §4.4): capacidad ≥ vendidos + en hold; "completa" se deriva. @return array{departure: Departure, warnings: list<string>} */
    public function updateDeparture(Departure $departure, array $data, User $actor): array
    {
        return $this->locked($departure, function (Departure $departure) use ($data, $actor) {
            $data = Arr::only($data, self::DEPARTURE_FIELDS);
            if (isset($data['status']) && ! in_array($data['status'], ['abierta', 'cerrada', 'cancelada'], true)) {
                throw ApiException::invalid('status', 'Estado inválido: “completa” se calcula automáticamente.');
            }
            $merged = $data + $departure->only(self::DEPARTURE_FIELDS);
            $warnings = $this->validateDeparture($merged);
            $held = $this->held($departure);
            if ((int) $merged['capacity'] < $departure->sold + $held) {
                throw ApiException::invalid('capacity', "La capacidad no puede ser menor que vendidos + en hold ({$departure->sold} + {$held}).");
            }
            $before = $this->departureLabel($departure);
            $wasCancelled = $departure->status === 'cancelada';
            $departure->fill($data);
            if (in_array($departure->status, ['abierta', 'completa'], true)) {
                $departure->status = $departure->sold >= $departure->capacity ? 'completa' : 'abierta';
            }
            if (! $departure->isDirty()) {
                return ['departure' => $departure, 'warnings' => $warnings];
            }
            $departure->save();
            $this->audit->log('Salida', $departure->ref, 'modificar', $before, $this->departureLabel($departure), actor: $actor);
            if (! $wasCancelled && $departure->status === 'cancelada' && $departure->sold > 0) {
                foreach (CatalogRecipients::operations() as $user) {
                    $this->notify->push('salida.cancelada', 'email', $user, "Salida cancelada con pasajeros: {$departure->ref}",
                        "{$departure->sold} pasajero(s) vendidos. Reprogramar pasajeros.", ['related_type' => 'catalog_departure', 'related_id' => $departure->id]);
                }
            }

            return ['departure' => $departure, 'warnings' => $warnings];
        });
    }

    /** CAT-BR-21: nuevo hold; 409 con alternativas si no hay cupo. */
    public function hold(Departure $departure, array $data, User $actor): Hold
    {
        $quantity = (int) $data['quantity'];
        $minutes = (int) ($data['minutes'] ?? $this->holdMinutes());
        $reference = trim((string) ($data['reference'] ?? ''));
        if ($reference === '') {
            throw ApiException::invalid('reference', 'Indique la referencia (cotización, reserva o pedido).');
        }
        if ($minutes < self::MIN_HOLD_MINUTES) {
            throw ApiException::invalid('minutes', 'La vigencia mínima del hold es de '.self::MIN_HOLD_MINUTES.' minutos.');
        }
        if ($quantity < 1) {
            throw ApiException::invalid('quantity', 'La cantidad debe ser al menos 1.');
        }

        return $this->locked($departure, function (Departure $departure) use ($quantity, $minutes, $reference, $data, $actor) {
            $this->ensureBookable($departure, true);
            $available = $this->available($departure);
            if ($quantity > $available) {
                throw $this->insufficient($departure, $quantity, $available);
            }
            $hold = Hold::create([
                'ref' => $this->sequences->next('catalog_hold'),
                'departure_id' => $departure->id,
                'quantity' => $quantity,
                'reference' => $reference,
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'status' => 'hold',
                'expires_at' => now()->addMinutes($minutes),
                'notes' => $data['notes'] ?? null,
            ]);
            $this->audit->log('Disponibilidad', $hold->ref, 'hold', null, "{$departure->ref} · {$quantity} cupo(s) · {$reference} · vence {$hold->expires_at->toIso8601String()}", actor: $actor);

            return $hold;
        });
    }

    /** CAT-BR-22: hold → confirmada. */
    public function confirm(Hold $hold, User $actor): Hold
    {
        return $this->locked($hold->departure, function (Departure $departure) use ($hold, $actor) {
            $hold->refresh();
            if ($hold->status !== 'hold') {
                throw ApiException::unprocessable('CAT-BR-22', "El hold {$hold->ref} no está retenido (estado {$hold->status}).");
            }
            if ($hold->expires_at->isPast()) {
                throw ApiException::unprocessable('CAT-BR-22', "El hold {$hold->ref} expiró; cree uno nuevo.");
            }
            $this->ensureProductActive($departure);
            if ($departure->sold + $hold->quantity > $departure->capacity) {
                throw ApiException::unprocessable('PRD-BR-012', "PRD-BR-012: confirmar {$hold->quantity} cupo(s) excede la capacidad de {$departure->ref}.");
            }
            $this->addSold($departure, $hold->quantity);
            $hold->update(['status' => 'confirmada']);
            $this->audit->log('Disponibilidad', $hold->ref, 'confirmar', 'hold', "confirmada · {$departure->ref} vendidos {$departure->sold}/{$departure->capacity}", actor: $actor);
            $creator = $hold->created_by ? User::find($hold->created_by) : null;
            if ($creator) {
                $this->notify->push('disponibilidad.confirmada', 'portal', $creator, "Cupos confirmados: {$hold->ref}", "{$hold->quantity} cupo(s) en {$departure->ref} · {$hold->reference}",
                    ['related_type' => 'catalog_hold', 'related_id' => $hold->id]);
            }

            return $hold;
        });
    }

    /** CAT-BR-23: venta directa sin hold previo (queda una fila `confirmada`). */
    public function confirmSeats(Departure $departure, array $data, User $actor): Hold
    {
        $quantity = (int) $data['quantity'];
        $reference = trim((string) ($data['reference'] ?? ''));
        if ($reference === '') {
            throw ApiException::invalid('reference', 'Indique la referencia de la venta.');
        }
        if ($quantity < 1) {
            throw ApiException::invalid('quantity', 'La cantidad debe ser al menos 1.');
        }

        return $this->locked($departure, function (Departure $departure) use ($quantity, $reference, $data, $actor) {
            $this->ensureBookable($departure, false);
            $available = $this->available($departure);
            if ($quantity > $available) {
                throw $this->insufficient($departure, $quantity, $available, 'Sobreventa bloqueada');
            }
            $this->addSold($departure, $quantity);
            $hold = Hold::create([
                'ref' => $this->sequences->next('catalog_hold'),
                'departure_id' => $departure->id,
                'quantity' => $quantity,
                'reference' => $reference,
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'status' => 'confirmada',
                'expires_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);
            $this->audit->log('Disponibilidad', $hold->ref, 'confirmar', null, "venta directa · {$departure->ref} · {$quantity} cupo(s) · {$reference}", actor: $actor);

            return $hold;
        });
    }

    /** CAT-BR-24: liberar con motivo; si estaba confirmada devuelve `sold`. */
    public function release(Hold $hold, string $reason, User $actor): Hold
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ApiException::invalid('reason', 'Indique el motivo de la liberación.');
        }

        return $this->locked($hold->departure, function (Departure $departure) use ($hold, $reason, $actor) {
            $hold->refresh();
            if (! in_array($hold->status, ['hold', 'confirmada'], true)) {
                throw ApiException::unprocessable('CAT-BR-24', "El hold {$hold->ref} ya está {$hold->status}.");
            }
            $before = $hold->status;
            if ($before === 'confirmada') {
                $this->addSold($departure, -$hold->quantity);
            }
            $hold->update(['status' => 'liberada', 'released_reason' => $reason]);
            $this->audit->log('Disponibilidad', $hold->ref, 'liberar', $before, "liberada — {$reason}", actor: $actor);

            return $hold;
        });
    }

    public function consume(Hold $hold, User $actor): Hold
    {
        if ($hold->status !== 'confirmada') {
            throw ApiException::unprocessable('CAT-BR-24', 'Solo se consumen cupos confirmados.');
        }

        return DB::transaction(function () use ($hold, $actor) {
            $hold->update(['status' => 'consumida']);
            $this->audit->log('Disponibilidad', $hold->ref, 'consumir', 'confirmada', 'consumida', actor: $actor);

            return $hold;
        });
    }

    /**
     * Varias salidas bajo lock (Reservas §4.3 paso 6): locks Redis y FOR UPDATE en orden fijo de id para evitar deadlocks.
     * El trabajo recibe las salidas recargadas indexadas por id y corre dentro de la transacción del llamador.
     *
     * @param  list<string>  $departureIds
     */
    public function lockMany(array $departureIds, callable $work): mixed
    {
        $ids = array_values(array_unique(array_filter($departureIds)));
        sort($ids);
        $locks = [];
        try {
            foreach ($ids as $id) {
                $lock = Cache::lock("catalog:departure:{$id}", 30);
                if (! $lock->block(5)) {
                    throw ApiException::conflict('DEPARTURE_LOCKED', 'Otra operación está usando los cupos de esta salida; intente de nuevo');
                }
                $locks[] = $lock;
            }

            return DB::transaction(function () use ($ids, $work) {
                $departures = $ids ? Departure::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id') : collect();

                return $work($departures);
            });
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    /** Venta confirmada de cupos sobre una salida ya bloqueada (Reservas): hold `confirmada` + `sold`. */
    public function bookLocked(Departure $departure, int $quantity, string $reference, string $referenceId, User $actor): Hold
    {
        $this->addSold($departure, $quantity);
        $hold = Hold::create([
            'ref' => $this->sequences->next('catalog_hold'), 'departure_id' => $departure->id, 'quantity' => $quantity,
            'reference' => $reference, 'reference_type' => 'reservation', 'reference_id' => $referenceId,
            'status' => 'confirmada', 'expires_at' => now(),
        ]);
        $this->audit->log('Disponibilidad', $hold->ref, 'confirmar', null, "{$reference} · {$departure->ref} · {$quantity} cupo(s) · vendidos {$departure->sold}/{$departure->capacity}", actor: $actor);

        return $hold;
    }

    /** Libera los cupos confirmados de una reserva en una salida ya bloqueada (cancelación, reprogramación). */
    public function releaseLocked(Departure $departure, string $referenceId, string $reason, User $actor): int
    {
        $released = 0;
        foreach (Hold::where('departure_id', $departure->id)->where('reference_type', 'reservation')->where('reference_id', $referenceId)->where('status', 'confirmada')->get() as $hold) {
            $this->addSold($departure, -min($hold->quantity, $departure->sold));
            $hold->update(['status' => 'liberada', 'released_reason' => $reason]);
            $this->audit->log('Disponibilidad', $hold->ref, 'liberar', 'confirmada', "liberada — {$reason} · {$departure->ref} vendidos {$departure->sold}/{$departure->capacity}", actor: $actor);
            $released += $hold->quantity;
        }

        return $released;
    }

    /** Suma cupos a los ya confirmados de la reserva en la salida (modificación: agregar pasajeros). */
    public function addToBooking(Departure $departure, string $referenceId, int $quantity, User $actor): void
    {
        $hold = Hold::where('departure_id', $departure->id)->where('reference_type', 'reservation')->where('reference_id', $referenceId)->where('status', 'confirmada')->first();
        if ($hold === null) {
            return;
        }
        $this->addSold($departure, $quantity);
        $hold->update(['quantity' => $hold->quantity + $quantity]);
        $this->audit->log('Disponibilidad', $hold->ref, 'confirmar', null, "+{$quantity} cupo(s) · {$hold->reference} · {$departure->ref} vendidos {$departure->sold}/{$departure->capacity}", actor: $actor);
    }

    /** JOB-01 / CAT-BR-25: holds vencidos → expirada. Idempotente. @return list<string> refs */
    public function expireHolds(?User $actor = null): array
    {
        $refs = [];
        Hold::where('status', 'hold')->where('expires_at', '<', now())
            // Los holds de reservas online los procesa `booking:run expire-holds` (anula la reserva impaga).
            ->where(fn ($q) => $q->whereNull('reference_type')->orWhere('reference_type', '<>', 'booking_engine'))->orderBy('expires_at')->get()
            ->each(function (Hold $hold) use (&$refs, $actor) {
                DB::transaction(function () use ($hold, &$refs, $actor) {
                    $affected = Hold::whereKey($hold->id)->where('status', 'hold')->update(['status' => 'expirada', 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
                    if ($affected === 1) {
                        $refs[] = $hold->ref;
                        $this->audit->log('Disponibilidad', $hold->ref, 'expirar', 'hold', 'expirada', actor: $actor, system: $actor === null);
                    }
                });
            });
        Cache::put($this->lastRunKey(), ['at' => now()->toIso8601String(), 'result' => count($refs).' hold(s) expirado(s)'], now()->addDays(7));

        return $refs;
    }

    public function lastExpiryRun(): ?array
    {
        return Cache::get($this->lastRunKey());
    }

    /** Holds vencidos aún no procesados (aviso de la pantalla de salidas). */
    public function pendingExpired(): int
    {
        return Hold::where('status', 'hold')->where('expires_at', '<', now())->count();
    }

    /** Hasta 3 salidas futuras abiertas del mismo producto con cupo suficiente (CAT-BR-21). */
    public function alternatives(Departure $departure, int $quantity): array
    {
        $candidates = Departure::where('product_id', $departure->product_id)->where('id', '<>', $departure->id)->where('status', 'abierta')
            ->whereDate('departure_date', '>=', $this->clock->today()->toDateString())->orderBy('departure_date')->orderBy('departure_time')->limit(30)->get();
        $held = $this->heldMany($candidates->pluck('id'));

        return $candidates->map(fn (Departure $d) => ['id' => $d->id, 'ref' => $d->ref, 'date' => $d->departure_date->toDateString(), 'time' => substr((string) $d->departure_time, 0, 5),
            'available' => $this->available($d, $held[$d->id] ?? 0)])
            ->filter(fn ($d) => $d['available'] >= $quantity)->take(3)->values()->all();
    }

    private function insufficient(Departure $departure, int $quantity, int $available, string $title = 'Cupo insuficiente'): ApiException
    {
        $alternatives = $this->alternatives($departure, $quantity);
        $list = $alternatives ? ' Alternativas: '.implode(', ', array_map(fn ($a) => "{$a['ref']} ({$a['date']}, {$a['available']} libres)", $alternatives)).'.' : '';

        return ApiException::rule('PRD-BR-012', "Regla transversal 4 / PRD-BR-012: {$title}. Solicitados {$quantity}, disponibles {$available} en {$departure->ref}.{$list}",
            ['available' => $available, 'requested' => $quantity, 'alternatives' => $alternatives]);
    }

    private function ensureBookable(Departure $departure, bool $hold): void
    {
        $this->ensureProductActive($departure);
        if ($departure->status !== 'abierta') {
            throw ApiException::unprocessable('CAT-BR-21', "La salida {$departure->ref} no está abierta (estado {$departure->status}).");
        }
        if ($hold && $departure->departure_date->toDateString() < $this->clock->today()->toDateString()) {
            throw ApiException::unprocessable('CAT-BR-21', "La salida {$departure->ref} ya pasó.");
        }
    }

    private function ensureProductActive(Departure $departure): void
    {
        $product = Product::withTrashed()->find($departure->product_id);
        if (! $product || $product->status !== Product::ACTIVE) {
            throw ApiException::unprocessable('PRD-BR-002', "PRD-BR-002: el producto {$product?->ref} no está ACTIVO; no se puede usar en nuevas ventas.");
        }
    }

    private function addSold(Departure $departure, int $delta): void
    {
        $departure->sold += $delta;
        if (in_array($departure->status, ['abierta', 'completa'], true)) {
            $departure->status = $departure->sold >= $departure->capacity ? 'completa' : 'abierta';
        }
        $departure->save();
    }

    /** Lock Redis por salida + transacción; recarga la salida dentro del lock. */
    private function locked(Departure $departure, callable $work): mixed
    {
        return Cache::lock("catalog:departure:{$departure->id}", 10)->block(5, function () use ($departure, $work) {
            return DB::transaction(function () use ($departure, $work) {
                $fresh = Departure::whereKey($departure->id)->lockForUpdate()->firstOrFail();

                return $work($fresh);
            });
        });
    }

    /** @return list<string> */
    private function validateDeparture(array $data): array
    {
        $warnings = [];
        $capacity = (int) $data['capacity'];
        if ($capacity < 1 || $capacity > self::MAX_CAPACITY) {
            throw ApiException::invalid('capacity', 'La capacidad debe estar entre 1 y '.self::MAX_CAPACITY.'.');
        }
        if (trim((string) ($data['meeting_point'] ?? '')) === '') {
            throw ApiException::invalid('meeting_point', 'Indique el punto de encuentro.');
        }
        if (! empty($data['provider_id'])) {
            $provider = Provider::find($data['provider_id']);
            if (! $provider?->isUsable()) {
                throw ApiException::invalid('provider_id', 'El proveedor no está activo o su contrato está vencido (PRD-BR-007).');
            }
        }
        if (! empty($data['guide_id'])) {
            $guide = $this->links->guide($data['guide_id']) ?? throw ApiException::invalid('guide_id', 'Guía inexistente.');
            if ($guide['on_vacation'] ?? false) {
                throw ApiException::invalid('guide_id', 'El guía está de vacaciones en esa fecha.');
            }
        }
        if (! empty($data['vehicle_id'])) {
            $vehicle = $this->links->vehicle($data['vehicle_id']) ?? throw ApiException::invalid('vehicle_id', 'Vehículo inexistente.');
            if ($vehicle['in_maintenance'] ?? false) {
                throw ApiException::invalid('vehicle_id', 'El vehículo está en mantenimiento.');
            }
            if (($vehicle['seats'] ?? PHP_INT_MAX) < $capacity) {
                $warnings[] = "El vehículo tiene {$vehicle['seats']} asientos, menos que la capacidad de la salida.";
            }
        }

        return $warnings;
    }

    private function departureLabel(Departure $d): string
    {
        return "{$d->departure_date->toDateString()} ".substr((string) $d->departure_time, 0, 5)." · capacidad {$d->capacity} · vendidos {$d->sold} · {$d->status}";
    }

    private function lastRunKey(): string
    {
        return 'catalog:holds:last-expiry:'.$this->tenant->id();
    }
}
