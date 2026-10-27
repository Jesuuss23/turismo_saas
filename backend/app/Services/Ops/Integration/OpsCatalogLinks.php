<?php

namespace App\Services\Ops\Integration;

use App\Models\Bookings\Reservation;
use App\Models\Ops\Guide;
use App\Models\Ops\Order;
use App\Models\Ops\Vehicle;
use App\Services\Bookings\Integration\ReservationCatalogLinks;

/**
 * Catálogo lee guías y vehículos reales de Operaciones (spec 02 CAT-BR-20) y el uso del producto en órdenes.
 * Sin recursos cargados aún (tenant nuevo) cae en la configuración del tenant.
 */
class OpsCatalogLinks extends ReservationCatalogLinks
{
    public function productUsage(string $productId, ?string $ownerId = null): array
    {
        $usage = parent::productUsage($productId, $ownerId);
        $reservations = Reservation::whereHas('services', fn ($q) => $q->where('product_id', $productId))->pluck('id');
        $usage['operations'] = Order::whereIn('reservation_id', $reservations)->orderByDesc('date_from')->limit(100)->get()
            ->map(fn (Order $o) => ['code' => $o->code, 'title' => $o->title, 'status' => $o->status, 'date' => $o->date_from->toDateString()])->all();

        return $usage;
    }

    public function guides(): array
    {
        $guides = Guide::orderBy('code')->get();

        return $guides->isEmpty() ? parent::guides()
            : $guides->map(fn (Guide $g) => ['id' => $g->id, 'name' => $g->full_name, 'on_vacation' => in_array($g->status, ['vacaciones', 'descanso'], true)])->all();
    }

    public function vehicles(): array
    {
        $vehicles = Vehicle::orderBy('code')->get();

        return $vehicles->isEmpty() ? parent::vehicles()
            : $vehicles->map(fn (Vehicle $v) => ['id' => $v->id, 'plate' => $v->label(), 'seats' => $v->capacity, 'in_maintenance' => $v->status === 'mantenimiento'])->all();
    }
}
