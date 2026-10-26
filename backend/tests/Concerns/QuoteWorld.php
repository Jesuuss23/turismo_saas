<?php

namespace Tests\Concerns;

use App\Models\Catalog\Product;
use App\Models\Crm\Client;
use Illuminate\Testing\TestResponse;

/**
 * Escenario de cotizaciones sobre CatalogWorld: un producto activo con tarifa USD (costo 220, precio 380)
 * y un cliente de la cartera de cada asesor de Ventas (u03, u04).
 */
trait QuoteWorld
{
    protected Product $product;

    /** @var array<string, Client> */
    protected array $clients = [];

    public function quoteWorld(): void
    {
        $this->catalogWorld();
        $this->product = $this->makeProduct([], Product::ACTIVE);
        $this->clients = ['u03' => $this->makeClient('u03'), 'u04' => $this->makeClient('u04', ['name' => 'Luis Ramírez'])];
    }

    /** Payload del constructor con una línea de la tarifa Directo/Adulto (precio sugerido por el backend). */
    public function quotePayload(string $owner = 'u03', array $overrides = [], array $item = []): array
    {
        $rate = $this->inTenant(fn () => $this->product->rates()->where('channel', 'Directo')->value('id'));

        return $overrides + [
            'client_id' => $this->clients[$owner]->id, 'title' => 'Viaje a Machu Picchu',
            'travel_from' => now()->addDays(20)->toDateString(), 'travel_to' => now()->addDays(23)->toDateString(),
            'currency' => 'USD', 'pax_adt' => 2, 'pax_chd' => 0, 'pax_inf' => 0, 'discount_pct' => 0,
            'items' => [$item + ['product_id' => $this->product->id, 'rate_id' => $rate, 'service_date' => now()->addDays(21)->toDateString()]],
        ];
    }

    public function makeQuote(string $owner = 'u03', array $overrides = [], array $item = []): array
    {
        return $this->qPost($owner, 'quotes', $this->quotePayload($owner, $overrides, $item), $this->idem())->assertCreated()->json('data');
    }

    public function qGet(string $who, string $uri): TestResponse
    {
        return $this->as($who)->getJson('/api/v1/'.ltrim($uri, '/'));
    }

    public function qPost(string $who, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->as($who)->withHeaders($headers)->postJson('/api/v1/'.ltrim($uri, '/'), $data);
    }

    /** If-Match con el lock_version actual de la cotización. */
    public function quoteLock(string $code): array
    {
        return ['If-Match' => (string) $this->admin()->table('quote_quotes')->where('code', $code)->value('lock_version')];
    }

    public function sendQuote(string $who, string $code, array $channels = ['Email']): TestResponse
    {
        return $this->qPost($who, "quotes/{$code}/send", ['channels' => $channels], $this->quoteLock($code) + $this->idem());
    }
}
