<?php

namespace App\Services\Bookings;

use App\Models\Catalog\Departure;
use App\Models\Catalog\Product;
use App\Services\Catalog\AvailabilityService;
use App\Services\Crm\CrmClock;

/**
 * `availFor(producto, fecha, pax)` (§4.3 paso 7, RT-4 / PRD-BR-012 / PRD-BR-013): POR_SALIDA exige salida
 * ese día con cupo libre; los demás modelos quedan como hold sujeto a confirmación del proveedor.
 */
class BookingInventory
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly CrmClock $clock,
    ) {}

    public static function bySalida(?Product $product): bool
    {
        return $product?->availability_model === 'POR_SALIDA';
    }

    /** Salida del producto en la fecha (no cerrada ni cancelada, fecha ≥ hoy). */
    public function departureFor(Product $product, string $date): ?Departure
    {
        if ($date < $this->clock->today()->toDateString()) {
            return null;
        }

        return Departure::where('product_id', $product->id)->whereDate('departure_date', $date)->whereIn('status', ['abierta', 'completa'])
            ->orderBy('departure_time')->first();
    }

    /**
     * @param  int  $alreadyHeld  pax de esta reserva ya retenidos en la misma salida (cuentan como libres)
     * @return array{ok: bool, rule: ?string, message: string, departure: ?array, departure_id: ?string, alternatives: list<array>, provider_hold: bool}
     */
    public function check(?Product $product, string $date, int $pax, ?Departure $departure = null, int $alreadyHeld = 0): array
    {
        $result = fn (bool $ok, ?string $rule, string $message, ?Departure $d = null, array $alternatives = [], bool $providerHold = false) => [
            'ok' => $ok, 'rule' => $rule, 'message' => $message, 'departure_id' => $d?->id, 'alternatives' => $alternatives, 'provider_hold' => $providerHold,
            'departure' => $d ? ['id' => $d->id, 'ref' => $d->ref, 'date' => $d->departure_date->toDateString(), 'time' => substr((string) $d->departure_time, 0, 5),
                'capacity' => $d->capacity, 'sold' => $d->sold, 'available' => $this->availability->available($d) + $alreadyHeld] : null,
        ];
        if ($product === null || $product->status !== Product::ACTIVE) {
            return $result(false, 'PRD-BR-002', "PRD-BR-002: el producto {$product?->ref} no está ACTIVO.");
        }
        if (! self::bySalida($product)) {
            return $result(true, 'PRD-BR-013', 'Hold solicitado al proveedor, sujeto a confirmación (PRD-BR-013).', null, [], true);
        }
        $departure ??= $this->departureFor($product, $date);
        if ($departure === null) {
            return $result(false, 'PRD-BR-012', "No hay salida programada de {$product->ref} el {$date} (PRD-BR-012).", null, $this->alternatives($product, $date, $pax));
        }
        $free = $this->availability->available($departure) + $alreadyHeld;
        if ($free < $pax) {
            return $result(false, 'PRD-BR-012', "Sin disponibilidad: la salida {$departure->ref} tiene {$free} cupo(s) libre(s) para {$pax} pax (PRD-BR-012).",
                $departure, $this->availability->alternatives($departure, $pax));
        }

        return $result(true, null, "Salida {$departure->ref}: {$free} cupo(s) libre(s).", $departure);
    }

    /** Salidas futuras abiertas del producto con cupo ≥ pax (cuando no hay salida en la fecha). */
    private function alternatives(Product $product, string $date, int $pax): array
    {
        return Departure::where('product_id', $product->id)->where('status', 'abierta')->whereDate('departure_date', '>=', max($date, $this->clock->today()->toDateString()))
            ->orderBy('departure_date')->limit(20)->get()
            ->map(fn (Departure $d) => ['id' => $d->id, 'ref' => $d->ref, 'date' => $d->departure_date->toDateString(), 'time' => substr((string) $d->departure_time, 0, 5), 'available' => $this->availability->available($d)])
            ->filter(fn ($d) => $d['available'] >= $pax)->take(3)->values()->all();
    }
}
