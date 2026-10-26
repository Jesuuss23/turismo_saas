<?php

use App\Models\Catalog\Product;
use App\Models\Catalog\Rate;

beforeEach(function () {
    $this->catalogWorld();
});

function seasonData(array $overrides = []): array
{
    return $overrides + ['name' => 'Alta julio', 'type' => 'Alta', 'date_from' => now()->addMonths(2)->startOfMonth()->toDateString(),
        'date_to' => now()->addMonths(2)->endOfMonth()->toDateString(), 'priority' => 10, 'factor' => 1.2];
}

it('PROC-019: temporadas solapadas con la misma prioridad → 422; con otra prioridad gana la de menor número', function () {
    $this->catPost('manager', 'seasons', seasonData())->assertCreated();
    $this->catPost('manager', 'seasons', seasonData(['name' => 'Feriado']))->assertStatus(422)->assertJsonPath('code', 'PROC-019');
    $this->catPost('manager', 'seasons', seasonData(['name' => 'Inti Raymi', 'type' => 'Feriado', 'priority' => 1, 'factor' => 1.5]))->assertCreated();

    $date = now()->addMonths(2)->startOfMonth()->addDays(3)->toDateString();
    $this->catGet('u03', "seasons/resolve?date={$date}")->assertOk()
        ->assertJsonPath('data.applicable.name', 'Inti Raymi')->assertJsonPath('data.others.0.name', 'Alta julio');
});

it('CAT-BR-17: temporada usada por una tarifa no se elimina (409); sin uso sí', function () {
    $season = $this->catPost('manager', 'seasons', seasonData())->json('data');
    $product = $this->makeProduct();
    $this->catPost('manager', "products/{$product->ref}/rates", ['rate_type' => 'Venta', 'segment' => 'Adulto', 'channel' => 'Directo', 'currency' => 'USD',
        'cost' => 200, 'price' => 400, 'season_id' => $season['id'], 'valid_from' => $season['date_from'], 'valid_to' => $season['date_to']])->assertCreated();
    $this->catSend('DELETE', 'manager', "seasons/{$season['id']}")->assertStatus(409)->assertJsonPath('code', 'CAT-BR-17');

    $free = $this->catPost('manager', 'seasons', seasonData(['name' => 'Baja', 'type' => 'Baja', 'date_from' => now()->addMonths(5)->toDateString(), 'date_to' => now()->addMonths(6)->toDateString()]))->json('data');
    $this->catSend('DELETE', 'manager', "seasons/{$free['id']}")->assertOk();
});

it('tarifas: vencida no se registra; cambio de precio exige motivo y respeta mínimo y máximo (§12-17), con audit', function () {
    $product = $this->makeProduct();
    $base = ['rate_type' => 'Venta', 'segment' => 'Niño', 'channel' => 'Directo', 'currency' => 'USD', 'cost' => 100, 'price' => 200];
    $this->catPost('manager', "products/{$product->ref}/rates", $base + ['valid_from' => now()->subYear()->toDateString(), 'valid_to' => now()->subDay()->toDateString()])
        ->assertStatus(422)->assertJsonValidationErrors('valid_to');

    $rate = $this->catPost('manager', "products/{$product->ref}/rates", $base + ['min_price' => 150, 'max_price' => 300,
        'valid_from' => now()->toDateString(), 'valid_to' => now()->addYear()->toDateString()])->assertCreated()->json('data');
    $uri = "products/{$product->ref}/rates/{$rate['id']}/price";
    $lock = fn () => $this->lockOf('catalog_rates', $rate['id']);
    $this->catSend('PATCH', 'manager', $uri, ['cost' => 100, 'price' => 140, 'reason' => 'x'], $lock())->assertStatus(422)->assertJsonValidationErrors('price');
    $this->catSend('PATCH', 'manager', $uri, ['cost' => 100, 'price' => 320, 'reason' => 'x'], $lock())->assertStatus(422)->assertJsonValidationErrors('price');
    $this->catSend('PATCH', 'manager', $uri, ['cost' => 100, 'price' => 250], $lock())->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->catSend('PATCH', 'manager', $uri, ['cost' => 100, 'price' => 250, 'reason' => 'Ajuste 2027'])->assertStatus(428);
    $this->catSend('PATCH', 'manager', $uri, ['cost' => 100, 'price' => 250, 'reason' => 'Ajuste 2027'], $lock())->assertOk()->assertJsonPath('data.price', '250.00');
    expect(collect($this->auditRows('Tarifa', $rate['ref']))->pluck('action')->all())->toContain('cambio_precio');
});

it('Ventas no ve tarifas netas ni costos y no puede crear tarifas (403)', function () {
    $product = $this->makeProduct();
    $rates = $this->catGet('u03', "products/{$product->ref}/rates")->assertOk()->assertJsonPath('meta.hidden_net', true)->json('data');
    expect(collect($rates)->pluck('channel')->all())->not->toContain('B2B')
        ->and(collect($rates)->every(fn ($r) => ! array_key_exists('cost', $r)))->toBeTrue();
    expect(collect($this->catGet('manager', "products/{$product->ref}/rates")->json('data'))->pluck('channel')->all())->toContain('B2B');

    $this->catPost('u03', "products/{$product->ref}/rates", ['rate_type' => 'Venta'])->assertForbidden();
});

it('CAT-BR-42: simulador aplica temporada y markup; Ventas no tiene acceso', function () {
    $product = $this->makeProduct();
    $rate = $this->inTenant(fn () => Rate::where('product_id', $product->id)->where('channel', 'Directo')->first());
    $this->catPost('manager', 'seasons', seasonData(['date_from' => now()->toDateString(), 'date_to' => now()->addDays(30)->toDateString(), 'factor' => 1.5]))->assertCreated();

    $result = $this->catPost('manager', 'pricing/simulate', ['product_id' => $product->ref, 'rate_id' => $rate->id, 'date' => now()->addDays(2)->toDateString(), 'pax' => 2, 'markup_override' => 10])
        ->assertOk()->json('data');
    expect($result['season']['factor'])->toBe('1.50')
        ->and($result['steps']['cost'])->toBe('440.00')
        ->and($result['steps']['base'])->toBe('660.00')
        ->and($result['steps']['markup'])->toBe('66.00');

    $this->catPost('finance', 'pricing/simulate', ['product_id' => $product->ref])->assertOk();
    $this->catPost('u03', 'pricing/simulate', ['product_id' => $product->ref])->assertForbidden();
});

it('resolver tarifa por fecha y canal devuelve la vigente aplicable', function () {
    $product = $this->makeProduct([], Product::ACTIVE);
    $this->catGet('manager', "products/{$product->ref}/rates/resolve?segment=Adulto&channel=Directo")->assertOk()
        ->assertJsonPath('data.applicable.price', '380.00');
});
