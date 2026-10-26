<?php

namespace Tests\Concerns;

use App\Models\Catalog\Category;
use App\Models\Catalog\Departure;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductType;
use App\Models\Catalog\Provider;
use App\Models\Catalog\SupplierService;
use App\Services\Catalog\AvailabilityService;
use App\Services\Catalog\DestinationService;
use App\Services\Catalog\ProductLifecycle;
use App\Services\Catalog\ProductService;
use App\Services\Catalog\ProviderService;
use App\Services\Catalog\SupplierServices;
use Illuminate\Testing\TestResponse;

/**
 * Escenario de catálogo sobre CrmWorld: destinos Perú › Cusco › Machu Picchu, un proveedor activo
 * con dos servicios vigentes y helpers para crear productos por los mismos servicios que la API.
 */
trait CatalogWorld
{
    /** @var array<string, string> */
    protected array $dest = [];

    protected Provider $provider;

    /** @var array<string, SupplierService> */
    protected array $svc = [];

    public function catalogWorld(): void
    {
        $this->crmWorld();
        $this->inTenant(function () {
            $destinations = app(DestinationService::class);
            $peru = $destinations->create(['name' => 'Perú', 'type' => 'País'], $this->users['admin'])['destination'];
            $cusco = $destinations->create(['name' => 'Cusco', 'type' => 'Departamento', 'parent_id' => $peru->id], $this->users['admin'])['destination'];
            $machu = $destinations->create(['name' => 'Machu Picchu', 'type' => 'Atracción', 'parent_id' => $cusco->id], $this->users['admin'])['destination'];
            $this->dest = ['peru' => $peru->id, 'cusco' => $cusco->id, 'machu' => $machu->id];

            $providers = app(ProviderService::class);
            $this->provider = $providers->create(['name' => 'PeruRail S.A.', 'type' => 'Tren', 'ruc' => '20431871808', 'contact_name' => 'Mesa', 'currency' => 'USD',
                'contract_start' => now()->subYear()->toDateString(), 'contract_end' => now()->addYear()->toDateString()], $this->users['admin']);
            $providers->activate($this->provider, $this->users['admin']);

            $services = app(SupplierServices::class);
            foreach (['tren' => ['Tren Expedition', 'Tren', 140], 'guia' => ['Guía', 'Guía', 80]] as $key => [$name, $type, $cost]) {
                $this->svc[$key] = $services->create(['provider_id' => $this->provider->id, 'name' => $name, 'type' => $type, 'unit' => 'pax', 'cost' => $cost, 'currency' => 'USD',
                    'valid_from' => now()->subMonth()->toDateString(), 'valid_to' => now()->addYear()->toDateString()], $this->users['admin'])['service'];
            }
        });
    }

    /** Payload del asistente con todo lo que el checklist exige. */
    public function productData(array $overrides = []): array
    {
        return $this->inTenant(fn () => $overrides + [
            'code' => 'MAPI-'.substr(uniqid(), -5),
            'name' => 'Machu Picchu Full Day',
            'product_type_id' => ProductType::where('name', 'EXCURSIÓN')->value('id'),
            'category_id' => Category::where('name', 'Cultural')->value('id'),
            'primary_destination_id' => $this->dest['machu'],
            'duration_value' => 1, 'duration_unit' => 'días', 'modality' => 'Compartido',
            'minimum_passengers' => 1, 'maximum_passengers' => 16,
            'components' => [['service_id' => $this->svc['tren']->id], ['service_id' => $this->svc['guia']->id]],
            'itinerary' => [['title' => 'Cusco – Machu Picchu', 'items' => [['start_time' => '06:00', 'activity' => 'Tren a Aguas Calientes', 'item_type' => 'Tren']]]],
            'rates' => [
                ['rate_type' => 'Venta', 'segment' => 'Adulto', 'channel' => 'Directo', 'currency' => 'USD', 'cost' => 220, 'price' => 380, 'valid_from' => now()->subDay()->toDateString(), 'valid_to' => now()->addYear()->toDateString()],
                ['rate_type' => 'B2B', 'segment' => 'Adulto', 'channel' => 'B2B', 'currency' => 'USD', 'cost' => 220, 'price' => 320, 'valid_from' => now()->subDay()->toDateString(), 'valid_to' => now()->addYear()->toDateString()],
            ],
        ]);
    }

    public function makeProduct(array $overrides = [], ?string $status = null): Product
    {
        $data = $this->productData($overrides);

        return $this->inTenant(function () use ($data, $status) {
            $product = app(ProductService::class)->create($data, $this->users['admin']);
            if ($status) {
                $this->moveTo($product, $status);
            }

            return $product->fresh();
        });
    }

    /** Lleva el producto al estado pedido por el ciclo de vida real. */
    public function moveTo(Product $product, string $status): void
    {
        $this->inTenant(function () use ($product, $status) {
            $life = app(ProductLifecycle::class);
            $admin = $this->users['admin'];
            $steps = [
                Product::REVIEW => fn () => $life->submitReview($product->fresh(), $admin),
                Product::APPROVED => fn () => $life->approve($product->fresh(), $admin),
                Product::ACTIVE => fn () => $life->activate($product->fresh(), $admin),
                Product::SUSPENDED => fn () => $life->suspend($product->fresh(), 'prueba', $admin),
            ];
            $order = array_keys($steps);
            $from = array_search($product->fresh()->status, $order, true);
            foreach (array_slice($steps, $from === false ? 0 : $from + 1, null, true) as $target => $step) {
                $step();
                if ($target === $status) {
                    return;
                }
            }
        });
    }

    public function makeDeparture(Product $product, array $overrides = []): Departure
    {
        return $this->inTenant(fn () => app(AvailabilityService::class)->createDeparture($product, $overrides + [
            'departure_date' => now()->addDays(10)->toDateString(), 'departure_time' => '06:00', 'capacity' => 10, 'meeting_point' => 'Plaza de Armas',
        ], $this->users['admin'])['departure']);
    }

    public function catGet(string $who, string $uri): TestResponse
    {
        return $this->as($who)->getJson('/api/v1/catalog/'.ltrim($uri, '/'));
    }

    public function catPost(string $who, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->as($who)->withHeaders($headers)->postJson('/api/v1/catalog/'.ltrim($uri, '/'), $data);
    }

    public function catSend(string $method, string $who, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->as($who)->withHeaders($headers)->json($method, '/api/v1/catalog/'.ltrim($uri, '/'), $data);
    }

    public function lockOf(string $table, string $id): array
    {
        return ['If-Match' => (string) $this->fresh($table, $id)->lock_version];
    }
}
