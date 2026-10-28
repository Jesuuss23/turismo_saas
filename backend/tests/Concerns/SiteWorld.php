<?php

namespace Tests\Concerns;

use App\Models\Catalog\Departure;
use App\Models\Catalog\Product;
use App\Services\Catalog\ProductParts;
use App\Services\Catalog\SeasonService;
use Illuminate\Testing\TestResponse;

/**
 * Escenario del sitio público (spec 15) sobre ChannelWorld: Machu Picchu Full Day (USD, Directo 380, salida a +21 días con 10 cupos) publicado
 * en la tienda, Laguna Humantay (PEN, E-commerce 129 < Directo 140, bajo solicitud), temporadas media ×1.00 (hoy..+40), baja ×0.88 (+41..+80)
 * y Año Nuevo ×1.40 (+50..+52, prioridad 5), y las promociones PRIMAVERA8 (MAPI) y «Grupo 4+» 10 % (Humantay).
 */
trait SiteWorld
{
    protected Product $humantay;

    /** @var array<string, string> token del carrito por clave */
    protected array $carts = [];

    public function siteWorld(): void
    {
        $this->channelWorld();
        $this->humantay = $this->makeProduct(['name' => 'Laguna Humantay', 'code' => 'HUM-'.substr(uniqid(), -5), 'rates' => [
            ['rate_type' => 'Venta', 'segment' => 'Adulto', 'channel' => 'Directo', 'currency' => 'PEN', 'cost' => 70, 'price' => 140, 'valid_from' => now()->subDay()->toDateString(), 'valid_to' => now()->addYear()->toDateString()],
            ['rate_type' => 'Venta', 'segment' => 'Adulto', 'channel' => 'E-commerce', 'currency' => 'PEN', 'cost' => 70, 'price' => 129, 'valid_from' => now()->subDay()->toDateString(), 'valid_to' => now()->addYear()->toDateString()],
        ]], Product::ACTIVE);
        $this->inTenant(function () {
            app(ProductParts::class)->setChannel($this->humantay->fresh(), 'ecommerce', true, $this->users['admin']);
            $seasons = app(SeasonService::class);
            foreach ([
                ['name' => 'Media', 'type' => 'Media', 'date_from' => now()->toDateString(), 'date_to' => now()->addDays(40)->toDateString(), 'factor' => 1.00, 'priority' => 10],
                ['name' => 'Baja', 'type' => 'Baja', 'date_from' => now()->addDays(41)->toDateString(), 'date_to' => now()->addDays(80)->toDateString(), 'factor' => 0.88, 'priority' => 10],
                ['name' => 'Año Nuevo', 'type' => 'Fecha especial', 'date_from' => now()->addDays(50)->toDateString(), 'date_to' => now()->addDays(52)->toDateString(), 'factor' => 1.40, 'priority' => 5],
            ] as $season) {
                $seasons->create($season, $this->users['admin']);
            }
        });
        $this->humantay = $this->inTenant(fn () => $this->humantay->fresh());
        $this->qPost('manager', "channels/ecommerce/products/{$this->product->ref}/promotions", ['name' => 'Primavera', 'value' => 8, 'promo_code' => 'PRIMAVERA8',
            'date_from' => now()->subDay()->toDateString(), 'date_to' => now()->addMonth()->toDateString()])->assertCreated();
        $this->qPost('manager', "channels/ecommerce/products/{$this->humantay->ref}/promotions", ['name' => 'Grupo 4+', 'value' => 10, 'min_pax' => 4,
            'date_from' => now()->subDay()->toDateString(), 'date_to' => now()->addMonth()->toDateString()])->assertCreated();
    }

    /** Llamada pública al sitio (tenant por `X-Tenant-Slug`), con token de carrito, pedido o sesión opcionales. */
    public function site(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->shop($method, 'public/'.ltrim($uri, '/'), $data, $headers);
    }

    public function newCart(string $key = 'a', string $currency = 'USD', array $headers = []): string
    {
        return $this->carts[$key] = $this->site('POST', 'carts', ['currency' => $currency], $headers + $this->idem())->assertCreated()->json('data.token');
    }

    public function cartHeaders(string $key = 'a'): array
    {
        return ['X-Cart-Token' => $this->carts[$key]];
    }

    public function addLine(string $key, Product $product, array $data = []): TestResponse
    {
        return $this->site('POST', 'carts/current/items', $data + ['product' => $product->ref, 'adults' => 2], $this->cartHeaders($key));
    }

    /** Línea de MAPI en la salida dada (por defecto la del escenario, a +21 días). */
    public function addMapi(string $key = 'a', int $adults = 2, ?Departure $departure = null, array $data = []): TestResponse
    {
        $departure ??= $this->resDeparture;

        return $this->addLine($key, $this->product, $data + ['date' => $departure->departure_date->toDateString(), 'departure_id' => $departure->id, 'adults' => $adults]);
    }

    public function sitePassengers(int $adults = 2, array $extra = []): array
    {
        $rows = [];
        for ($i = 1; $i <= $adults; $i++) {
            $rows[] = ['type' => 'ADT', 'first_name' => $i === 1 ? 'Valeria' : "Acompañante{$i}", 'last_name' => 'Campos', 'doc_type' => 'DNI',
                'doc_number' => (string) (70123455 + $i), 'birth_date' => '1990-05-1'.$i, 'nationality' => 'Perú'];
        }

        return array_merge($rows, $extra);
    }

    public function checkoutData(int $adults = 2, array $overrides = []): array
    {
        return array_replace_recursive([
            'buyer' => ['name' => 'Valeria Campos', 'email' => 'valeria@correo.test', 'phone' => '+51 987 654 321', 'country' => 'Perú', 'doc_type' => 'DNI', 'doc_number' => '70123456'],
            'billing' => ['type' => 'Boleta'], 'passengers' => $this->sitePassengers($adults),
            'consents' => ['terms' => true, 'cancellation' => true, 'privacy' => true], 'pay_mode' => 'total',
        ], $overrides);
    }

    /** @return array{code: string, token: string, data: array} */
    public function placeOrder(string $key = 'a', int $adults = 2, array $overrides = [], array $headers = []): array
    {
        $data = $this->site('POST', 'checkout/orders', $this->checkoutData($adults, $overrides), $this->cartHeaders($key) + $headers + $this->idem())->assertCreated()->json('data');

        return ['code' => $data['code'], 'token' => $data['token'], 'data' => $data];
    }

    public function payOrder(array $order, array $data, ?string $key = null): TestResponse
    {
        return $this->site('POST', "orders/{$order['code']}/payments", $data, ['X-Order-Token' => $order['token']] + $this->idem($key));
    }

    public function bearer(string $who): array
    {
        return ['Authorization' => 'Bearer '.$this->tokens[$who]];
    }

    public function holdsOf(string $refType = 'cart'): int
    {
        return (int) $this->admin()->table('catalog_availability_holds')->where('tenant_id', $this->tenant->id)->where('reference_type', $refType)->where('status', 'hold')->sum('quantity');
    }
}
