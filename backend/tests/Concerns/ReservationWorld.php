<?php

namespace Tests\Concerns;

use App\Models\Catalog\Departure;
use Illuminate\Testing\TestResponse;

/**
 * Escenario de reservas sobre QuoteWorld: el producto se vende por salida (POR_SALIDA) con una salida de 10 cupos
 * el día del servicio de la cotización (+21 días) y helpers para aprobar y convertir cotizaciones.
 */
trait ReservationWorld
{
    protected Departure $resDeparture;

    public function reservationWorld(int $capacity = 10): void
    {
        $this->quoteWorld();
        $this->admin()->table('catalog_products')->where('id', $this->product->id)->update(['availability_model' => 'POR_SALIDA']);
        $this->product = $this->inTenant(fn () => $this->product->fresh());
        $this->resDeparture = $this->makeDeparture($this->product, ['departure_date' => now()->addDays(21)->toDateString(), 'capacity' => $capacity]);
    }

    /** Cotización enviada y aprobada por el cliente (registrada por el asesor). */
    public function approvedQuote(string $owner = 'u03', array $overrides = []): array
    {
        $quote = $this->makeQuote($owner, $overrides);
        $this->sendQuote($owner, $quote['code'])->assertOk();
        $this->qPost($owner, "quotes/{$quote['code']}/approve", ['approved_by_name' => 'Ana', 'channel' => 'Mensaje de WhatsApp', 'ref' => 'chat'],
            $this->quoteLock($quote['code']) + $this->idem())->assertOk();

        return $quote;
    }

    public function convert(string $who, string $code, ?string $key = null): TestResponse
    {
        return $this->qPost($who, "quotes/{$code}/convert", [], $this->quoteLock($code) + $this->idem($key));
    }

    /** Reserva convertida desde una cotización aprobada de `$owner`. */
    public function makeReservation(string $owner = 'u03'): array
    {
        $quote = $this->approvedQuote($owner);

        return $this->convert('manager', $quote['code'])->assertCreated()->json('data');
    }

    public function resLock(string $code): array
    {
        return ['If-Match' => (string) $this->admin()->table('booking_reservations')->where('code', $code)->value('lock_version')];
    }

    public function rPost(string $who, string $code, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->qPost($who, "reservations/{$code}/".ltrim($uri, '/'), $data, $headers);
    }

    public function rGet(string $who, string $code, string $uri = ''): TestResponse
    {
        return $this->qGet($who, rtrim("reservations/{$code}/".ltrim($uri, '/'), '/'));
    }

    public function sold(?Departure $departure = null): int
    {
        return (int) $this->fresh('catalog_product_departures', ($departure ?? $this->resDeparture)->id)->sold;
    }
}
