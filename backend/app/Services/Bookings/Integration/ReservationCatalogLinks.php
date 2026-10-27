<?php

namespace App\Services\Bookings\Integration;

use App\Models\Bookings\BookedService;
use App\Models\Bookings\Reservation;
use App\Services\Quotes\QuoteCatalogLinks;

/** Uso del producto y reservas del proveedor en Catálogo (spec 02 §6.1 `usage`, ficha de proveedor). */
class ReservationCatalogLinks extends QuoteCatalogLinks
{
    public function productUsage(string $productId, ?string $ownerId = null): array
    {
        $reservations = Reservation::whereHas('services', fn ($q) => $q->where('product_id', $productId))
            ->when($ownerId, fn ($q) => $q->where('owner_id', $ownerId))
            ->with(['client:id,name', 'owner:id,name'])->orderByDesc('travel_from')->limit(100)->get();

        return ['reservations' => $reservations->map(fn (Reservation $r) => [
            'code' => $r->code, 'title' => $r->title, 'client' => $r->client?->name, 'owner' => $r->owner?->name,
            'status' => $r->status, 'date' => $r->travel_from->toDateString(), 'total' => (string) $r->total_amount, 'currency' => $r->currency,
        ])->all()] + parent::productUsage($productId, $ownerId);
    }

    public function providerReservations(string $providerId): array
    {
        return BookedService::where('booking_services.provider_id', $providerId)->whereNotIn('booking_services.status', [BookedService::CANCELLED, BookedService::REJECTED])
            ->join('booking_reservations as r', 'r.id', '=', 'booking_services.reservation_id')
            ->orderByDesc('booking_services.service_date')->limit(50)
            ->get(['booking_services.code as ref', 'r.code as code', 'booking_services.service_date as date', 'booking_services.status', 'booking_services.name'])
            ->map(fn ($s) => ['ref' => $s->ref, 'code' => $s->code, 'date' => substr((string) $s->date, 0, 10), 'status' => $s->status, 'name' => $s->name])->all();
    }
}
