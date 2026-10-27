<?php

namespace App\Services\Bookings\Integration;

use App\Models\Bookings\Reservation;
use App\Models\Iam\User;
use App\Services\Bookings\BookingAccess;
use App\Services\Search\SearchProvider;

/** Búsqueda global de reservas por código, título o cliente; Ventas solo las propias (RES-BR-50). */
class ReservationSearchProvider implements SearchProvider
{
    public function __construct(private readonly BookingAccess $access) {}

    public function search(User $user, string $term, int $limit): array
    {
        if (! $user->hasPermissionTo(BookingAccess::VIEW) || $user->isExternal()) {
            return [];
        }
        $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';

        return $this->access->scope(Reservation::query(), $user)
            ->where(fn ($q) => $q->whereRaw('lower(code) like ?', [$like])->orWhereRaw('lower(title) like ?', [$like])
                ->orWhereHas('client', fn ($c) => $c->whereRaw('lower(name) like ?', [$like])))
            ->with('client:id,name')->orderByDesc('travel_from')->limit($limit)->get()
            ->map(fn (Reservation $r) => ['type' => 'reservation', 'id' => $r->id, 'code' => $r->code, 'title' => $r->title,
                'subtitle' => ($r->client?->name ?? '').' · '.Reservation::STATUS_LABELS[$r->status], 'route' => "reservas/{$r->code}"])->all();
    }
}
