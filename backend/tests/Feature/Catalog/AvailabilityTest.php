<?php

use App\Models\Catalog\Product;
use App\Services\Catalog\AvailabilityService;

beforeEach(function () {
    $this->catalogWorld();
    $this->product = $this->makeProduct([], Product::ACTIVE);
    $this->departure = $this->makeDeparture($this->product, ['capacity' => 10]);
});

function holdFor(object $test, int $quantity, array $extra = []): array
{
    return $test->catPost('ops', 'holds', $extra + ['departure_id' => $test->departure->id, 'quantity' => $quantity, 'reference' => 'COT-001'], $test->idem())->json();
}

it('Operaciones programa salidas; Ventas no puede (403)', function () {
    $data = ['product_id' => $this->product->id, 'departure_date' => now()->addDays(20)->toDateString(), 'departure_time' => '07:30', 'capacity' => 12, 'meeting_point' => 'Hotel'];
    $this->catPost('ops', 'departures', $data, $this->idem())->assertCreated();
    $this->catPost('u03', 'departures', $data, $this->idem())->assertForbidden();
});

it('CAT-BR-20 / validaciones de salida: producto inactivo, fecha pasada y capacidad > 60', function () {
    $draft = $this->makeProduct();
    $base = ['departure_date' => now()->addDays(5)->toDateString(), 'departure_time' => '06:00', 'capacity' => 10, 'meeting_point' => 'Plaza'];
    $this->catPost('ops', 'departures', ['product_id' => $draft->id] + $base, $this->idem())->assertCreated();

    $this->admin()->table('catalog_products')->where('id', $this->product->id)->update(['status' => Product::INACTIVE]);
    $this->catPost('ops', 'departures', ['product_id' => $this->product->id] + $base, $this->idem())->assertStatus(422)->assertJsonPath('code', 'CAT-BR-20');
    $this->catPost('ops', 'departures', ['product_id' => $draft->id, 'departure_date' => now()->subDays(2)->toDateString()] + $base, $this->idem())
        ->assertStatus(422)->assertJsonValidationErrors('departure_date');
    $this->catPost('ops', 'departures', ['product_id' => $draft->id, 'capacity' => 61] + $base, $this->idem())->assertStatus(422)->assertJsonValidationErrors('capacity');
});

it('hold descuenta disponibilidad; exceso → 409 PRD-BR-012 con alternativas', function () {
    $other = $this->makeDeparture($this->product, ['departure_date' => now()->addDays(11)->toDateString(), 'capacity' => 20]);
    $this->catPost('ops', 'holds', ['departure_id' => $this->departure->id, 'quantity' => 7, 'reference' => 'COT-001'], $this->idem())
        ->assertCreated()->assertJsonPath('data.status', 'hold');
    $this->catGet('ops', "departures/{$this->departure->id}")->assertJsonPath('data.held', 7)->assertJsonPath('data.available', 3);

    $response = $this->catPost('ops', 'holds', ['departure_id' => $this->departure->id, 'quantity' => 4, 'reference' => 'COT-002'], $this->idem())
        ->assertStatus(409)->assertJsonPath('code', 'PRD-BR-012');
    expect(collect($response->json('alternatives'))->pluck('ref')->all())->toContain($other->ref);
});

it('hold sobre producto no ACTIVO → 422 PRD-BR-002', function () {
    $this->moveTo($this->product, Product::SUSPENDED);
    $this->catPost('ops', 'holds', ['departure_id' => $this->departure->id, 'quantity' => 1, 'reference' => 'COT-001'], $this->idem())
        ->assertStatus(422)->assertJsonPath('code', 'PRD-BR-002');
});

it('confirmar suma vendidos; liberar confirmado los devuelve; consumir solo confirmados', function () {
    $hold = holdFor($this, 4)['data'];
    $this->catPost('ops', "holds/{$hold['id']}/consume")->assertStatus(422)->assertJsonPath('code', 'CAT-BR-24');
    $this->catPost('ops', "holds/{$hold['id']}/confirm")->assertOk()->assertJsonPath('data.status', 'confirmada');
    expect($this->fresh('catalog_product_departures', $this->departure->id)->sold)->toBe(4);

    $this->catPost('ops', "holds/{$hold['id']}/release")->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->catPost('ops', "holds/{$hold['id']}/release", ['reason' => 'Cliente desistió'])->assertOk()->assertJsonPath('data.status', 'liberada');
    expect($this->fresh('catalog_product_departures', $this->departure->id)->sold)->toBe(0);
    $this->catPost('ops', "holds/{$hold['id']}/release", ['reason' => 'otra vez'])->assertStatus(422)->assertJsonPath('code', 'CAT-BR-24');
});

it('salida llena pasa a completa y vuelve a abierta al liberar', function () {
    $this->catPost('ops', "departures/{$this->departure->id}/confirm-seats", ['quantity' => 10, 'reference' => 'RES-9'], $this->idem())->assertCreated();
    expect($this->fresh('catalog_product_departures', $this->departure->id)->status)->toBe('completa');
    $hold = $this->catGet('ops', "holds?filter.departure={$this->departure->id}")->json('data.0');
    $this->catPost('ops', "holds/{$hold['id']}/release", ['reason' => 'Cambio de fecha'])->assertOk();
    expect($this->fresh('catalog_product_departures', $this->departure->id)->status)->toBe('abierta');
});

it('§12-15: un hold vencido deja de contar al instante, no se puede confirmar y JOB-01 es idempotente', function () {
    $hold = holdFor($this, 6, ['minutes' => 15])['data'];
    $this->travel(16)->minutes();

    $this->catGet('ops', "departures/{$this->departure->id}")->assertJsonPath('data.held', 0)->assertJsonPath('data.available', 10);
    $this->catPost('ops', "holds/{$hold['id']}/confirm")->assertStatus(422)->assertJsonPath('code', 'CAT-BR-22');

    $first = $this->inTenant(fn () => app(AvailabilityService::class)->expireHolds());
    $second = $this->inTenant(fn () => app(AvailabilityService::class)->expireHolds());
    expect($first)->toBe([$hold['ref']])->and($second)->toBe([])
        ->and($this->fresh('catalog_availability_holds', $hold['id'])->status)->toBe('expirada');
    expect(collect($this->auditRows('Disponibilidad', $hold['ref']))->pluck('action')->all())->toContain('expirar');
});

it('reducir capacidad por debajo de vendidos + hold → 422', function () {
    holdFor($this, 6);
    $this->catSend('PATCH', 'ops', "products/{$this->product->ref}/departures/{$this->departure->id}", ['capacity' => 5], $this->lockOf('catalog_product_departures', $this->departure->id))
        ->assertStatus(422)->assertJsonValidationErrors('capacity');
    $this->catSend('PATCH', 'ops', "products/{$this->product->ref}/departures/{$this->departure->id}", ['capacity' => 6], $this->lockOf('catalog_product_departures', $this->departure->id))
        ->assertOk();
});

it('holds secuenciales nunca sobrevenden: el último que no cabe recibe 409', function () {
    $codes = collect(range(1, 4))->map(fn ($i) => $this->catPost('ops', 'holds', ['departure_id' => $this->departure->id, 'quantity' => 3, 'reference' => "COT-{$i}"], $this->idem())->status());
    expect($codes->all())->toBe([201, 201, 201, 409]);
    $this->catGet('ops', "departures/{$this->departure->id}")->assertJsonPath('data.held', 9)->assertJsonPath('data.available', 1);
});

it('holds de otro tenant → 404', function () {
    $hold = holdFor($this, 1)['data'];
    $other = $this->makeTenant('inka');
    $token = $this->tokenFor($this->makeUser($other, 'tenant_admin'));
    $this->api($token)->postJson("/api/v1/catalog/holds/{$hold['id']}/confirm")->assertNotFound();
    $this->api($token)->getJson("/api/v1/catalog/departures/{$this->departure->id}")->assertNotFound();
});
