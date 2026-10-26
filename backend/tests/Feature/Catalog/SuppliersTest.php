<?php

use App\Models\Catalog\Product;

beforeEach(function () {
    $this->catalogWorld();
});

function providerData(array $overrides = []): array
{
    return $overrides + ['name' => 'Hotel Andino', 'type' => 'Hotel', 'ruc' => '20512345678', 'contact_name' => 'Recepción', 'currency' => 'PEN',
        'contract_start' => now()->subMonth()->toDateString(), 'contract_end' => now()->addYear()->toDateString()];
}

it('proveedor nace inactivo; RUC inválido o duplicado → 422; Operaciones lo activa y Manager no puede (403)', function () {
    $this->catPost('ops', 'providers', providerData(['ruc' => '30512345678']))->assertStatus(422)->assertJsonValidationErrors('ruc');
    $this->catPost('ops', 'providers', providerData(['ruc' => '20431871808']))->assertStatus(422)->assertJsonValidationErrors('ruc');
    $provider = $this->catPost('ops', 'providers', providerData())->assertCreated()->assertJsonPath('data.status', 'inactivo')->json('data');

    $this->catPost('manager', "providers/{$provider['id']}/activate")->assertForbidden();
    $this->catPost('ops', "providers/{$provider['id']}/activate")->assertOk()->assertJsonPath('data.status', 'activo');
});

it('CAT-BR-38: no se activa con contrato vencido; renovar con fecha futura lo permite', function () {
    $provider = $this->catPost('ops', 'providers', providerData(['contract_start' => now()->subYears(2)->toDateString(), 'contract_end' => now()->subDay()->toDateString()]))->json('data');
    $this->catPost('ops', "providers/{$provider['id']}/activate")->assertStatus(422)->assertJsonPath('code', 'CAT-BR-38');
    $this->catPost('ops', "providers/{$provider['id']}/renew-contract", ['contract_end' => now()->subDay()->toDateString()])->assertStatus(422);
    $this->catPost('ops', "providers/{$provider['id']}/renew-contract", ['contract_end' => now()->addYear()->toDateString()])->assertOk();
    $this->catPost('ops', "providers/{$provider['id']}/activate")->assertOk()->assertJsonPath('data.status', 'activo');
});

it('desactivar proveedor con productos activos avisa y notifica a gerencia; sus servicios no se pueden activar (PRD-BR-007)', function () {
    $product = $this->makeProduct([], Product::ACTIVE);
    $this->catPost('ops', "providers/{$this->provider->id}/deactivate")->assertStatus(422)->assertJsonValidationErrors('reason');
    $response = $this->catPost('ops', "providers/{$this->provider->id}/deactivate", ['reason' => 'Fin de convenio'])->assertOk()->assertJsonPath('data.status', 'inactivo');
    expect($response->json('meta.warnings.0'))->toContain($product->ref);

    $this->catPost('ops', "services/{$this->svc['tren']->id}/deactivate", ['reason' => 'x'])->assertOk();
    $this->catPost('ops', "services/{$this->svc['tren']->id}/activate")->assertStatus(422)->assertJsonPath('code', 'PRD-BR-007');
});

it('servicio con vigencia terminada no se activa (PRD-BR-003); cambio de costo queda auditado', function () {
    $service = $this->catPost('ops', 'services', ['provider_id' => $this->provider->id, 'name' => 'Bus Consettur', 'type' => 'Transporte', 'unit' => 'pax', 'cost' => 24, 'currency' => 'USD',
        'valid_from' => now()->subYear()->toDateString(), 'valid_to' => now()->subDay()->toDateString(), 'status' => 'inactivo'])->assertCreated()->json('data');
    $this->catPost('ops', "services/{$service['id']}/activate")->assertStatus(422)->assertJsonPath('code', 'PRD-BR-003');

    $this->catSend('PATCH', 'ops', "services/{$this->svc['guia']->id}", ['cost' => 95], $this->lockOf('catalog_services', $this->svc['guia']->id))->assertOk();
    expect(collect($this->auditRows('Servicio', $this->svc['guia']->ref))->pluck('action')->all())->toContain('cambio_costo');
});

it('Ventas no ve proveedores (403) ni costos de servicios', function () {
    $this->catGet('u03', 'providers')->assertForbidden();
    $services = $this->catGet('u03', 'services')->assertOk()->json('data');
    expect(collect($services)->every(fn ($s) => ! array_key_exists('cost', $s)))->toBeTrue();
    $this->catGet('finance', 'providers')->assertOk();
});

it('destinos: jerarquía válida, sin ciclos (PROC-011) y no se elimina con hijos o productos (CAT-BR-39)', function () {
    $this->catPost('manager', 'destinations', ['name' => 'Bolivia', 'type' => 'País', 'parent_id' => $this->dest['peru']])->assertStatus(422)->assertJsonValidationErrors('type');
    $this->catPost('manager', 'destinations', ['name' => 'Valle', 'type' => 'Ciudad'])->assertStatus(422)->assertJsonValidationErrors('parent_id');
    $this->catPost('manager', 'destinations', ['name' => 'cusco', 'type' => 'Departamento', 'parent_id' => $this->dest['peru']])->assertStatus(422)->assertJsonValidationErrors('name');

    $this->catSend('PATCH', 'manager', "destinations/{$this->dest['peru']}", ['parent_id' => $this->dest['machu']])->assertStatus(422);

    $this->catSend('DELETE', 'manager', "destinations/{$this->dest['cusco']}")->assertStatus(409)->assertJsonPath('code', 'CAT-BR-39');
    $this->makeProduct();
    $this->catSend('DELETE', 'manager', "destinations/{$this->dest['machu']}")->assertStatus(409)->assertJsonPath('code', 'CAT-BR-39');
});

it('filtro por destino incluye descendientes', function () {
    $product = $this->makeProduct();
    expect(collect($this->catGet('u03', "products?filter.destination={$this->dest['peru']}")->assertOk()->json('data'))->pluck('ref')->all())->toContain($product->ref);
});

it('CAT-BR-36: tipo o categoría en uso no se elimina (409); sin uso sí', function () {
    $product = $this->makeProduct();
    $this->catSend('DELETE', 'manager', "categories/{$product->category_id}")->assertStatus(409)->assertJsonPath('code', 'CAT-BR-36');
    $this->catSend('DELETE', 'manager', "product-types/{$product->product_type_id}")->assertStatus(409)->assertJsonPath('code', 'CAT-BR-36');

    $type = $this->catPost('manager', 'product-types', ['name' => 'Crucero'])->assertCreated()->json('data');
    $this->catPost('manager', 'product-types', ['name' => 'crucero'])->assertStatus(422);
    $this->catSend('DELETE', 'manager', "product-types/{$type['id']}")->assertOk();
});
