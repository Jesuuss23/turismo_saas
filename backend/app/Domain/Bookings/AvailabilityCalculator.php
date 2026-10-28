<?php

namespace App\Domain\Bookings;

use App\Models\Catalog\AvailabilityRange;
use App\Models\Catalog\Departure;
use App\Models\Catalog\Product;
use App\Services\Catalog\AvailabilityService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Insumos de `dayStatus` para una ventana de fechas (spec 16 §8.1): rangos vivos, salidas programadas, vendidos
 * (servicios activos del producto en reservas no borrador/canceladas, un conteo por reserva) y holds vigentes.
 * Se cargan en bloque para que el calendario mensual haga 4 consultas, no 4 por día.
 */
class AvailabilityCalculator
{
    /** Holds que ya están reflejados como servicios de una reserva del motor (no se descuentan dos veces). */
    public const ENGINE_HOLD = 'booking_engine';

    public function __construct(private readonly AvailabilityService $availability) {}

    /**
     * @return array{ranges: Collection<int, AvailabilityRange>, has_ranges: bool, departures: array<string, Departure>, dep_held: array<string, int>, sold: array<string, int>, held: array<string, int>}
     */
    public function window(Product $product, string $from, string $to, bool $lock = false): array
    {
        $ranges = AvailabilityRange::where('product_id', $product->id)->where('date_from', '<=', $to)->where('date_to', '>=', $from)
            ->orderBy('date_from')->orderBy('code')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $hasRanges = $ranges->isNotEmpty() || AvailabilityRange::where('product_id', $product->id)->exists();
        $departures = Departure::where('product_id', $product->id)->whereBetween('departure_date', [$from, $to])->whereIn('status', ['abierta', 'completa'])
            ->orderBy('departure_date')->orderBy('departure_time')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $byDate = [];
        foreach ($departures as $d) {
            $byDate[$d->departure_date->toDateString()] ??= $d;
        }

        return [
            'ranges' => $ranges,
            'has_ranges' => $hasRanges,
            'departures' => $byDate,
            'dep_held' => $departures->isEmpty() ? [] : $this->availability->heldMany($departures->pluck('id')),
            'sold' => $this->sold($product, $from, $to),
            'held' => $this->held($product, $from, $to),
        ];
    }

    /** Pax vendidos por fecha (sin salida programada): máximo de pax por reserva, sumado entre reservas. @return array<string, int> */
    public function sold(Product $product, string $from, string $to): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT t.service_date::text AS d, sum(t.pax)::int AS pax FROM (
              SELECT s.reservation_id, s.service_date, max(coalesce(s.pax, 0)) AS pax
              FROM booking_services s JOIN booking_reservations r ON r.id = s.reservation_id
              WHERE s.product_id = ? AND s.service_date BETWEEN ? AND ? AND s.departure_id IS NULL
                AND s.status NOT IN ('cancelado', 'rechazado') AND r.status NOT IN ('borrador', 'cancelada')
              GROUP BY s.reservation_id, s.service_date
            ) t GROUP BY t.service_date
            SQL, [$product->id, $from, $to]);

        return collect($rows)->mapWithKeys(fn ($r) => [$r->d => (int) $r->pax])->all();
    }

    /** Cupos retenidos por fecha (holds vigentes sin reserva del motor). @return array<string, int> */
    public function held(Product $product, string $from, string $to): array
    {
        return DB::table('catalog_availability_holds')->where('product_id', $product->id)->whereNull('departure_id')->whereBetween('service_date', [$from, $to])
            ->where('status', 'hold')->where('expires_at', '>', now())
            ->where(fn ($q) => $q->whereNull('reference_type')->orWhere('reference_type', '<>', self::ENGINE_HOLD))
            ->groupBy('service_date')->selectRaw('service_date::text AS d, sum(quantity)::int AS q')->pluck('q', 'd')->map(fn ($q) => (int) $q)->all();
    }

    /** Rango efectivo de una fecha: la excepción (cerrado/agotado) prevalece sobre el disponible (§8.1 paso 7). */
    public function effectiveRange(Collection $ranges, string $date): ?AvailabilityRange
    {
        $hits = $ranges->filter(fn (AvailabilityRange $r) => $r->covers($date))->values();

        return $hits->first(fn (AvailabilityRange $r) => $r->status !== 'disponible') ?? $hits->first();
    }
}
