<?php

namespace App\Services\Bookings\Integration;

use App\Models\Bookings\Reservation;
use App\Models\Iam\User;
use App\Services\Bookings\BookingAccess;
use App\Services\Bookings\ReceivableService;
use Illuminate\Support\Collection;

/** Exportación CSV de la lista de reservas (§2) con el alcance y las columnas del rol (Operaciones: costos, sin total ni saldo). */
class ReservationExporter
{
    public const RESOURCES = ['reservations.reservations' => ['reservations.view']];

    public function __construct(
        private readonly BookingAccess $access,
        private readonly ReceivableService $receivables,
    ) {}

    /** @return array{0: list<string>, 1: Collection<int, list<mixed>>} */
    public function rows(string $resource, User $user, array $filters = []): array
    {
        $ops = $this->access->opsView($user);
        $rows = $this->access->scope(Reservation::query(), $user)->with(['client:id,code,name', 'owner:id,name', 'services'])->withCount('passengers')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['channel'] ?? null, fn ($q, $c) => $q->where('channel', $c))
            ->when(! $this->access->ownOnly($user) && ($filters['owner'] ?? null), fn ($q) => $q->where('owner_id', $filters['owner']))
            ->orderByDesc('travel_from')->get();

        return [
            ['Código', 'Título', 'Cliente', 'Asesor', 'Estado', 'Canal', 'Inicio', 'Fin', 'Pax', 'Moneda', ...($ops ? ['Servicios conf.', 'Costo proveedores'] : ['Total', 'Saldo'])],
            $rows->map(function (Reservation $r) use ($ops) {
                $active = $r->services->whereNotIn('status', ['cancelado', 'rechazado']);

                return [
                    $r->code, $r->title, $r->client?->name, $r->owner?->name, Reservation::STATUS_LABELS[$r->status], $r->channel,
                    $r->travel_from->toDateString(), $r->travel_to->toDateString(), $r->passengers_count, $r->currency,
                    ...($ops
                        ? [$active->whereIn('status', ['confirmado', 'completado'])->count().'/'.$active->count(), number_format((float) $active->sum('cost_amount'), 2, '.', '')]
                        : [(string) $r->total_amount, $this->receivables->balance($r)]),
                ];
            }),
        ];
    }
}
