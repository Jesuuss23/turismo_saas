<?php

namespace Tests\Concerns;

use App\Models\Channel\B2bAgency;
use App\Models\Channel\B2bPriceList;
use App\Services\Catalog\ProductParts;
use App\Services\Channels\B2bAgencyService;
use Illuminate\Testing\TestResponse;

/**
 * Escenario de canales sobre ReservationWorld: el producto (USD, público 380) se vende por B2B y en la tienda; dos agencias
 * (AGB-01 con línea de PEN 5000 y tarifa neta 320 a crédito; AGB-02 sin línea ni tarifa), sus usuarios B2B y un usuario
 * cliente por cada cartera de Ventas.
 */
trait ChannelWorld
{
    /** @var array<string, B2bAgency> */
    protected array $agencies = [];

    public function channelWorld(): void
    {
        $this->reservationWorld();
        $this->inTenant(function () {
            $service = app(B2bAgencyService::class);
            $list = B2bPriceList::where('name', 'Mayorista 2026')->value('id');
            $base = ['contact_name' => 'Contacto', 'price_list_id' => $list, 'commission_pct' => 10, 'valid_from' => now()->subMonth()->toDateString(),
                'valid_to' => now()->addYear()->toDateString()];
            $this->agencies['AGB-01'] = $service->create($base + ['name' => 'Sol Viajes', 'ruc' => '20512345671', 'booking_email' => 'reservas@sol.test', 'credit_limit' => 5000], $this->users['admin']);
            $this->agencies['AGB-02'] = $service->create($base + ['name' => 'Andes Partners', 'ruc' => '20587654329', 'booking_email' => 'ops@partners.test', 'credit_limit' => 0], $this->users['admin']);
            foreach ($this->agencies as $code => $agency) {
                $agency->forceFill(['code' => $code])->save();
            }
            $parts = app(ProductParts::class);
            $parts->saveB2bRate($this->product->fresh(), ['agency_id' => $this->agencies['AGB-01']->id, 'price_list' => 'Mayorista 2026', 'price' => 320, 'commission_pct' => 10,
                'is_credit' => true, 'valid_to' => now()->addYear()->toDateString()], $this->users['admin']);
            $parts->setChannel($this->product->fresh(), 'b2b', true, $this->users['admin']);
            $parts->setChannel($this->product->fresh(), 'ecommerce', true, $this->users['admin']);
        });
        $this->product = $this->inTenant(fn () => $this->product->fresh());
        $this->addUser('b2b1', 'b2b', ['agency_id' => $this->agencies['AGB-01']->id, 'client_id' => $this->agencies['AGB-01']->client_id]);
        $this->addUser('b2b2', 'b2b', ['agency_id' => $this->agencies['AGB-02']->id, 'client_id' => $this->agencies['AGB-02']->client_id]);
        $this->addUser('customer', 'customer', ['client_id' => $this->clients['u03']->id]);
        $this->addUser('customer2', 'customer', ['client_id' => $this->clients['u04']->id]);
    }

    public function agency(string $code): object
    {
        return $this->fresh('channel_b2b_agencies', $this->agencies[$code]->id);
    }

    public function b2bReserve(string $who = 'b2b1', array $data = [], ?string $key = null): TestResponse
    {
        return $this->qPost($who, 'b2b/reservations', $data + ['product_id' => $this->product->id, 'departure_id' => $this->resDeparture->id, 'pax' => 2,
            'pay_mode' => 'credito', 'passengers' => "Ana Torres — 45678912\nLuis Vega — 47896541", 'agency_ref' => 'SV-1'], $this->idem($key));
    }

    public function shop(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders($headers + ['Accept' => 'application/json', 'X-Tenant-Slug' => $this->tenant->slug])
            ->json($method, '/api/v1/'.ltrim($uri, '/'), $data);
    }

    public function buyer(array $overrides = []): array
    {
        return $overrides + ['name' => 'Valeria Campos', 'doc_type' => 'DNI', 'doc_number' => '70123456', 'email' => 'valeria@correo.test', 'phone' => '+51 987 654 321',
            'country' => 'Perú', 'accept_privacy' => true];
    }

    /** Pedido web (hold) de `$adults` adultos en la salida del escenario. @return array{code: string, token: string} */
    public function webOrder(int $adults = 1, array $extra = []): array
    {
        $companions = array_map(fn ($i) => ['full_name' => "Acompañante {$i}", 'doc_number' => "7000000{$i}"], range(1, max(0, $adults - 1)));
        $data = $this->shop('POST', 'public/shop/orders', $extra + ['product' => $this->product->ref, 'departure_id' => $this->resDeparture->id, 'adults' => $adults,
            'buyer' => $this->buyer(), 'passengers' => array_slice($companions, 0, $adults - 1)], $this->idem())->assertCreated()->json('data');

        return ['code' => $data['code'], 'token' => $data['token']];
    }

    public function card(string $number = '4111111111111111'): array
    {
        return ['card' => ['number' => $number, 'expiry' => '12/29', 'cvv' => '123']];
    }
}
