<?php

namespace App\Providers;

use App\Domain\Bookings\AvailabilityCache;
use App\Domain\Bookings\RuleResolver;
use App\Events\Bookings\ReservationCancelled;
use App\Events\Bookings\ReservationCreated;
use App\Events\Catalog\AvailabilityClosedWithSales;
use App\Events\Catalog\AvailabilityRangeChanged;
use App\Events\Catalog\BookingRuleChanged;
use App\Models\Bookings\BookedService;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Notify;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Motor de reservas (spec 16 §8.2): invalidación de cachés y aviso a Operaciones. */
class BookingEngineServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // InvalidateAvailabilityCache (síncrono).
        Event::listen(AvailabilityRangeChanged::class, fn (AvailabilityRangeChanged $e) => app(AvailabilityCache::class)->bump($e->productId));
        // InvalidateRulesCache: la versión de reglas entra en la clave de disponibilidad, así basta un INCR.
        Event::listen(BookingRuleChanged::class, function (BookingRuleChanged $e) {
            app(RuleResolver::class)->bump();
            if ($e->productId) {
                app(AvailabilityCache::class)->bump($e->productId);
            }
        });
        // Reserva creada o cancelada en cualquier canal: los vendidos cambian.
        Event::listen([ReservationCreated::class, ReservationCancelled::class], function ($e) {
            $cache = app(AvailabilityCache::class);
            foreach (BookedService::where('reservation_id', $e->reservationId)->whereNotNull('product_id')->distinct()->pluck('product_id') as $productId) {
                $cache->bump($productId);
            }
        });
        // NotifyOperationsAffectedBookings (§8.5: `disponibilidad.cerrada_con_ventas`, email a Operaciones).
        Event::listen(AvailabilityClosedWithSales::class, function (AvailabilityClosedWithSales $e) {
            $lines = array_map(fn ($a) => "{$a['date']}: {$a['pax']} pax (".implode(', ', $a['reservations']).')', $e->affected);
            foreach (CatalogRecipients::operations() as $user) {
                app(Notify::class)->push('disponibilidad.cerrada_con_ventas', 'email', $user, "Fechas cerradas con ventas: {$e->productRef} {$e->productName}",
                    'Las reservas no se anulan; coordine la reprogramación. '.implode(' · ', $lines), ['related_type' => 'catalog_product', 'related_id' => $e->productId]);
            }
        });
    }
}
