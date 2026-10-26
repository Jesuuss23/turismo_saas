<?php

use App\Models\Catalog\Departure;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductItem;
use App\Models\Catalog\ProductType;
use App\Services\Catalog\ProductService;
use App\Services\Catalog\ProviderService;
use App\Services\Catalog\SupplierServices;

beforeEach(function () {
    $this->catalogWorld();
});

it('PRD-T01/T03: Manager crea en DRAFT con versión 0.1 borrador, ERP publicado y audit crear', function () {
    $response = $this->catPost('manager', 'products', $this->productData(['code' => 'mapi-01', 'channels' => ['ecommerce']]), $this->idem())
        ->assertCreated()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.current_version.version', '0.1')
        ->assertJsonPath('data.current_version.status', 'borrador');
    $ref = $response->json('data.ref');
    expect($ref)->toBe('PRD-001')
        ->and($response->json('data.sku'))->toBe('AND-MAPI-01')
        ->and($response->json('data.internal_name'))->toBe('Machu Picchu Full Day');

    $channels = $this->catGet('manager', "products/{$ref}/channels")->assertOk()->json('data');
    $erp = collect($channels)->firstWhere('channel', 'erp');
    $ecommerce = collect($channels)->firstWhere('channel', 'ecommerce');
    expect($erp['is_published'])->toBeTrue()->and($ecommerce['is_published'])->toBeFalse()->and($ecommerce['is_requested'])->toBeTrue();
    expect(collect($this->auditRows('Producto', $ref))->pluck('action')->all())->toContain('crear');

    $this->catGet('u03', "products/{$ref}")->assertOk();
});

it('PRD-T02/T14: producto y sub-recursos de otro tenant → 404', function () {
    $product = $this->makeProduct();
    $other = $this->makeTenant('inka');
    $outsider = $this->makeUser($other, 'tenant_admin');
    $token = $this->tokenFor($outsider);

    $this->api($token)->getJson("/api/v1/catalog/products/{$product->ref}")->assertNotFound();
    $this->api($token)->getJson("/api/v1/catalog/products/{$product->id}/components")->assertNotFound();
    $this->api($token)->getJson("/api/v1/catalog/products/{$product->id}/rates")->assertNotFound();
});

it('CAT-BR-01: código interno duplicado sin distinguir mayúsculas → 422', function () {
    $this->makeProduct(['code' => 'MAPI-FD']);
    $this->catPost('manager', 'products', $this->productData(['code' => 'mapi-fd']), $this->idem())
        ->assertStatus(422)->assertJsonPath('errors.code.0', 'El código interno ya existe en el tenant.');
});

it('CAT-BR-02: mínimo mayor que máximo en pasajeros → 422', function () {
    $this->catPost('manager', 'products', $this->productData(['minimum_passengers' => 10, 'maximum_passengers' => 2]), $this->idem())->assertStatus(422);
});

it('activar desde DRAFT o REVIEW → 422 checklist (ítem Estado) y audit activacion_rechazada', function () {
    $product = $this->makeProduct();
    $this->catPost('manager', "products/{$product->ref}/activate", [], $this->lockOf('catalog_products', $product->id))
        ->assertStatus(422)->assertJsonPath('code', 'PRD-ACT-CHECKLIST')->assertJsonPath('failed.0.key', 'status');
    expect($this->fresh('catalog_products', $product->id)->status)->toBe('DRAFT')
        ->and(collect($this->auditRows('Producto', $product->ref))->pluck('action'))->toContain('activacion_rechazada');

    $this->catPost('manager', "products/{$product->ref}/submit-review", [], $this->lockOf('catalog_products', $product->id))->assertOk()->assertJsonPath('data.status', 'REVIEW');
    $this->catPost('manager', "products/{$product->ref}/activate", [], $this->lockOf('catalog_products', $product->id))->assertStatus(422);
});

it('flujo completo: revisión → aprobar → activar (checklist 12/12) → versión vigente y notificación a ventas', function () {
    $product = $this->makeProduct();
    foreach (['submit-review' => 'REVIEW', 'approve' => 'APPROVED', 'activate' => 'ACTIVE'] as $action => $status) {
        $this->catPost('manager', "products/{$product->ref}/{$action}", [], $this->lockOf('catalog_products', $product->id))->assertOk()->assertJsonPath('data.status', $status);
    }
    $this->catGet('manager', "products/{$product->ref}/activation-checklist")->assertOk()->assertJsonPath('meta.ok', 12)->assertJsonPath('meta.total', 12);
    expect($this->admin()->table('catalog_product_versions')->where('product_id', $product->id)->value('status'))->toBe('vigente')
        ->and($this->admin()->table('notif_messages')->where('event', 'producto.activado')->exists())->toBeTrue();
});

it('activar APPROVED con día de itinerario sin actividades (CIRCUITO) → 422 ítem Itinerario', function () {
    $type = $this->inTenant(fn () => ProductType::where('name', 'CIRCUITO')->value('id'));
    $product = $this->makeProduct(['product_type_id' => $type, 'itinerary' => [
        ['title' => 'Día 1', 'items' => [['activity' => 'Llegada']]], ['title' => 'Día 2 sin actividades'],
    ]], Product::APPROVED);
    $response = $this->catPost('manager', "products/{$product->ref}/activate", [], $this->lockOf('catalog_products', $product->id))->assertStatus(422);
    expect(collect($response->json('failed'))->pluck('key')->all())->toBe(['itinerary']);
});

it('activar POR_SALIDA sin salidas futuras abiertas → 422 ítem Disponibilidad', function () {
    $product = $this->makeProduct(['availability_model' => 'POR_SALIDA'], Product::APPROVED);
    $response = $this->catPost('manager', "products/{$product->ref}/activate", [], $this->lockOf('catalog_products', $product->id))->assertStatus(422);
    expect(collect($response->json('failed'))->pluck('key')->all())->toBe(['availability']);

    $this->makeDeparture($product);
    $this->catPost('manager', "products/{$product->ref}/activate", [], $this->lockOf('catalog_products', $product->id))->assertOk();
});

it('PRD-T07: componente de servicio vencido o proveedor inactivo → 422 PRD-BR-007 y activación bloqueada', function () {
    $product = $this->makeProduct([], Product::APPROVED);
    $this->admin()->table('catalog_services')->where('id', $this->svc['guia']->id)->update(['valid_to' => now()->subDays(2)->toDateString(), 'valid_from' => now()->subMonths(2)->toDateString()]);

    $this->catPost('manager', "products/{$product->ref}/components", ['service_id' => $this->svc['guia']->id])->assertStatus(422)->assertJsonPath('code', 'PRD-BR-007');
    $failed = collect($this->catPost('manager', "products/{$product->ref}/activate", [], $this->lockOf('catalog_products', $product->id))->assertStatus(422)->json('failed'));
    expect($failed->pluck('key')->all())->toBe(['providers']);
});

it('CAT-BR-07: suspender, inactivar, archivar y devolver exigen motivo', function () {
    $active = $this->makeProduct([], Product::ACTIVE);
    foreach (['suspend', 'deactivate', 'archive'] as $action) {
        $this->catPost('manager', "products/{$active->ref}/{$action}", [], $this->lockOf('catalog_products', $active->id))->assertStatus(422)->assertJsonPath('errors.reason.0', 'Indique el motivo.');
    }
    $review = $this->makeProduct([], Product::REVIEW);
    $this->catPost('manager', "products/{$review->ref}/return-to-draft", [], $this->lockOf('catalog_products', $review->id))->assertStatus(422);
    $this->catPost('manager', "products/{$review->ref}/return-to-draft", ['reason' => 'Faltan fotos'], $this->lockOf('catalog_products', $review->id))->assertOk()->assertJsonPath('data.status', 'DRAFT');

    $this->catPost('manager', "products/{$active->ref}/suspend", ['reason' => 'Huelga'], $this->lockOf('catalog_products', $active->id))->assertOk()->assertJsonPath('data.status', 'SUSPENDED');
    expect($this->admin()->table('notif_messages')->where('event', 'producto.no_vendible')->exists())->toBeTrue();
});

it('transiciones exigen If-Match (SEC-BR-17)', function () {
    $product = $this->makeProduct();
    $this->catPost('manager', "products/{$product->ref}/submit-review")->assertStatus(428);
    $this->catPost('manager', "products/{$product->ref}/submit-review", [], ['If-Match' => '99'])->assertStatus(409)->assertJsonPath('code', 'STALE_VERSION');
});

it('DELETE: con paquete que lo contiene → 409 PRD-BR-018 intacto; sin uso → soft delete', function () {
    $component = $this->makeProduct([], Product::ACTIVE);
    $this->makeProduct(['components' => [['component_product_id' => $component->id]]]);

    $this->catSend('DELETE', 'manager', "products/{$component->ref}", ['reason' => 'Ya no se vende'])->assertStatus(409)->assertJsonPath('code', 'PRD-BR-018')
        ->assertJsonCount(1, 'packages');
    expect($this->fresh('catalog_products', $component->id)->deleted_at)->toBeNull()
        ->and(collect($this->auditRows('Producto', $component->ref))->pluck('action'))->toContain('eliminacion_bloqueada');

    $free = $this->makeProduct();
    $this->catSend('DELETE', 'manager', "products/{$free->ref}")->assertStatus(422);
    $this->catSend('DELETE', 'manager', "products/{$free->ref}", ['reason' => 'Error de carga'])->assertOk();
    expect($this->fresh('catalog_products', $free->id)->deleted_at)->not->toBeNull();
    $this->catGet('manager', "products/{$free->ref}")->assertNotFound();
});

it('CAT-BR-09: duplicar crea DRAFT (copia) con code -C01, tarifas nuevas, sin salidas y versión 0.1', function () {
    $product = $this->makeProduct(['code' => 'MAPI'], Product::ACTIVE);
    $this->makeDeparture($product);
    $copy = $this->catPost('manager', "products/{$product->ref}/duplicate", [], $this->idem())->assertCreated()->json('data');
    expect($copy['code'])->toBe('MAPI-C01')->and($copy['status'])->toBe('DRAFT')->and($copy['name'])->toBe('Machu Picchu Full Day (copia)');

    $rates = $this->admin()->table('catalog_rates')->where('product_id', $copy['id'])->pluck('ref')->all();
    expect($rates)->toHaveCount(2)->and($rates)->not->toContain('TAR-001')
        ->and(Departure::withoutGlobalScopes()->where('product_id', $copy['id'])->count())->toBe(0)
        ->and($this->admin()->table('catalog_product_versions')->where('product_id', $copy['id'])->value('version'))->toBe('0.1');
});

it('PRD-T12: nueva versión deja la anterior histórica y ambas consultables; comparación', function () {
    $product = $this->makeProduct([], Product::ACTIVE);
    $v1 = $this->catPost('manager', "products/{$product->ref}/versions", ['kind' => 'major', 'valid_from' => now()->toDateString(), 'reason' => 'Lanzamiento'])->assertCreated();
    expect($v1->json('data.version'))->toBe('1.0')->and($v1->json('data.status'))->toBe('vigente');

    $this->inTenant(fn () => app(ProductService::class)->update(Product::find($product->id), ['name' => 'Machu Picchu Express'], $this->users['admin']));
    $v2 = $this->catPost('manager', "products/{$product->ref}/versions", ['kind' => 'minor', 'valid_from' => now()->toDateString(), 'reason' => 'Cambio de nombre'])->assertCreated();
    expect($v2->json('data.version'))->toBe('1.1');

    $versions = collect($this->catGet('manager', "products/{$product->ref}/versions")->assertOk()->json('data'));
    expect($versions->firstWhere('version', '1.0')['status'])->toBe('historica')->and($versions->firstWhere('version', '1.1')['status'])->toBe('vigente');
    $this->catGet('manager', "products/{$product->ref}/versions/{$v1->json('data.id')}")->assertOk()->assertJsonPath('data.snapshot.name', 'Machu Picchu Full Day');
    $diff = $this->catGet('manager', "products/{$product->ref}/versions/compare?a={$v1->json('data.id')}&b={$v2->json('data.id')}")->assertOk()->json('data.diff');
    expect(collect($diff)->pluck('field'))->toContain('name');

    $this->catPost('manager', "products/{$product->ref}/versions", ['kind' => 'minor', 'valid_from' => now()->toDateString(), 'reason' => ''])->assertStatus(422);
});

it('PRD-T10: modificar el producto no altera el snapshot de versiones (inmutable en BD)', function () {
    $product = $this->makeProduct();
    $version = $this->admin()->table('catalog_product_versions')->where('product_id', $product->id)->first();
    $this->catSend('PATCH', 'manager', "products/{$product->ref}", ['name' => 'Nombre nuevo'], $this->lockOf('catalog_products', $product->id))->assertOk()->assertJsonPath('data.name', 'Nombre nuevo');
    expect(json_decode($this->fresh('catalog_product_versions', $version->id)->snapshot, true)['name'])->toBe('Machu Picchu Full Day');

    expect(fn () => $this->admin()->table('catalog_product_versions')->where('id', $version->id)->update(['reason' => 'x']))->toThrow(Exception::class);
});

it('CAT-BR-11: proveedor principal se asigna del primer servicio; el cambio exige motivo y queda auditado', function () {
    $product = $this->makeProduct();
    expect($product->main_provider_id)->toBe($this->provider->id);
    $other = $this->inTenant(function () {
        $p = app(ProviderService::class)->create(['name' => 'Guías SAC', 'type' => 'Guía', 'ruc' => '20600555666', 'contact_name' => 'Elsa', 'currency' => 'PEN',
            'contract_start' => now()->subYear()->toDateString(), 'contract_end' => now()->addYear()->toDateString()], $this->users['admin']);

        return $p;
    });
    $this->catSend('PUT', 'manager', "products/{$product->ref}/main-provider", ['provider_id' => $other->id, 'reason' => 'Mejor tarifa'])->assertStatus(422)->assertJsonPath('code', 'PRD-BR-007');
    $this->catPost('admin', "providers/{$other->id}/activate")->assertOk();
    $this->catSend('PUT', 'manager', "products/{$product->ref}/main-provider", ['provider_id' => $other->id])->assertStatus(422);
    $this->catSend('PUT', 'manager', "products/{$product->ref}/main-provider", ['provider_id' => $other->id, 'reason' => 'Mejor tarifa'])->assertOk();
    expect(collect($this->auditRows('Producto', $product->ref))->pluck('action'))->toContain('cambio_proveedor');
});

it('CAT-BR-10/12: componentes (orden, producto no ACTIVE) e itinerario (renumeración y servicio vinculado)', function () {
    $product = $this->makeProduct();
    $draft = $this->makeProduct();
    $this->catPost('manager', "products/{$product->ref}/components", ['component_product_id' => $draft->id])->assertStatus(422)->assertJsonPath('code', 'PRD-BR-002');
    $this->catPost('manager', "products/{$product->ref}/components", ['component_product_id' => $product->id])->assertStatus(422);

    $items = $this->inTenant(fn () => ProductItem::where('product_id', $product->id)->orderBy('sort_order')->pluck('id')->all());
    $this->catPost('manager', "products/{$product->ref}/components/reorder", ['ids' => array_reverse($items)])->assertOk();
    $order = collect($this->catGet('manager', "products/{$product->ref}/components")->json('data'))->pluck('service.name')->all();
    expect($order)->toBe(['Guía', 'Tren Expedition']);

    $day2 = $this->catPost('manager', "products/{$product->ref}/itineraries/days", ['title' => 'Día 2'])->assertCreated()->json('data');
    $this->catPost('manager', "products/{$product->ref}/itineraries/days", ['title' => 'Día 3'])->assertCreated();
    $this->catPost('manager', "products/{$product->ref}/itineraries/days/{$day2['id']}/items", ['activity' => 'Visita', 'service_id' => $this->svc['tren']->id])->assertCreated();
    $other = $this->inTenant(fn () => app(SupplierServices::class)->create(['provider_id' => $this->provider->id, 'name' => 'Bus', 'type' => 'Transporte', 'unit' => 'pax',
        'cost' => 10, 'currency' => 'USD', 'valid_from' => now()->toDateString(), 'valid_to' => now()->addYear()->toDateString()], $this->users['admin'])['service']);
    $this->catPost('manager', "products/{$product->ref}/itineraries/days/{$day2['id']}/items", ['activity' => 'Bus', 'service_id' => $other->id])->assertStatus(422);
    $this->catPost('manager', "products/{$product->ref}/itineraries/days/{$day2['id']}/items", ['activity' => 'Libre', 'start_time' => '08:00'])->assertCreated();

    $days = $this->catGet('manager', "products/{$product->ref}/itineraries")->json('data.days');
    expect(collect($days[1]['items'])->pluck('activity')->all())->toBe(['Libre', 'Visita']);

    $this->catSend('DELETE', 'manager', "products/{$product->ref}/itineraries/days/{$days[0]['id']}")->assertOk();
    $days = $this->catGet('manager', "products/{$product->ref}/itineraries")->json('data.days');
    expect(collect($days)->pluck('day_number')->all())->toBe([1, 2])->and($days[0]['title'])->toBe('Día 2');
});

it('CAT-BR-28: publicar con producto DRAFT → 422; ERP no despublicable; B2B sincroniza is_b2b', function () {
    $product = $this->makeProduct();
    $this->catSend('PUT', 'manager', "products/{$product->ref}/channels/ecommerce", ['published' => true])->assertStatus(422)->assertJsonPath('code', 'PRD-BR-002');
    $this->catSend('PUT', 'manager', "products/{$product->ref}/channels/erp", ['published' => false])->assertStatus(422);

    $this->inTenant(fn () => $this->moveTo($product, Product::ACTIVE));
    $this->catSend('PUT', 'manager', "products/{$product->ref}/channels/b2b", ['published' => true])->assertOk();
    expect($this->fresh('catalog_products', $product->id)->is_b2b)->toBeTrue();
    $this->catSend('PUT', 'manager', "products/{$product->ref}/channels/b2b", ['published' => false])->assertOk();
    expect($this->fresh('catalog_products', $product->id)->is_b2b)->toBeFalse();
});

it('CAT-BR-29/33/34: slug duplicado, inclusión repetida y relacionado consigo mismo → 422', function () {
    $a = $this->makeProduct();
    $b = $this->makeProduct();
    $this->catSend('PUT', 'manager', "products/{$a->ref}/content", ['slug' => 'Machu Picchu Día'])->assertOk()->assertJsonPath('data.slug', 'machu-picchu-dia');
    $this->catSend('PUT', 'manager', "products/{$b->ref}/content", ['slug' => 'machu-picchu-dia'])->assertStatus(422)->assertJsonPath('errors.slug.0', 'El slug ya está en uso por otro producto.');

    $this->catPost('manager', "products/{$a->ref}/inclusions", ['kind' => 'exclude', 'text' => 'propinas'])->assertStatus(422)->assertJsonPath('errors.text.0', 'Ya está en la lista.');
    $this->catPost('manager', "products/{$a->ref}/related", ['related_product_id' => $a->id, 'relation' => 'Upsell'])->assertStatus(422);
    $this->catPost('manager', "products/{$a->ref}/related", ['related_product_id' => $b->id, 'relation' => 'Upsell'])->assertCreated();
    $this->catGet('manager', "products/{$b->ref}/related")->assertOk()->assertJsonPath('data.referenced_by.0.product.ref', $a->ref);
});

it('CAT-BR-27: promoción porcentual de 60 % → 422; código en mayúsculas', function () {
    $product = $this->makeProduct();
    $promo = ['name' => 'Promo', 'kind' => 'Descuento %', 'value' => 60, 'date_from' => now()->toDateString(), 'date_to' => now()->addMonth()->toDateString()];
    $this->catPost('manager', "products/{$product->ref}/promotions", $promo)->assertStatus(422);
    $this->catPost('manager', "products/{$product->ref}/promotions", ['value' => 15, 'kind' => 'Código promocional', 'promo_code' => 'verano15'] + $promo)
        ->assertCreated()->assertJsonPath('data.promo_code', 'VERANO15')->assertJsonPath('data.ref', 'PROMO-01');
});

it('CAT-BR-26: precio B2B bajo el costo, agencia bloqueada o crédito sin línea → 422', function () {
    $this->admin()->table('tenants')->where('id', $this->tenant->id)->update(['settings' => json_encode(['security' => ['mfa_internal_required' => false], 'b2b' => ['agencies' => [
        ['id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Sol Viajes', 'status' => 'activa', 'credit_line' => 0],
        ['id' => '22222222-2222-4222-8222-222222222222', 'name' => 'Bloqueada', 'status' => 'bloqueada', 'credit_line' => 1000],
    ]]])]);
    $product = $this->makeProduct();
    $base = ['agency_id' => '11111111-1111-4111-8111-111111111111', 'price_list' => 'Lista 2026', 'price' => 300, 'valid_to' => now()->addYear()->toDateString()];
    $this->catPost('manager', "products/{$product->ref}/b2b-rates", ['price' => 100] + $base)->assertStatus(422)->assertJsonPath('code', 'CAT-BR-26');
    $this->catPost('manager', "products/{$product->ref}/b2b-rates", ['agency_id' => '22222222-2222-4222-8222-222222222222'] + $base)->assertStatus(422);
    $this->catPost('manager', "products/{$product->ref}/b2b-rates", ['is_credit' => true] + $base)->assertStatus(422);
    $this->catPost('manager', "products/{$product->ref}/b2b-rates", $base)->assertCreated();
    $this->catGet('u03', "products/{$product->ref}/b2b-rates")->assertForbidden();
});

it('PRD-T13 / RBAC: Ventas no edita producto, tarifas ni canales; Finanzas lee rentabilidad pero no edita', function () {
    $product = $this->makeProduct([], Product::ACTIVE);
    $this->catSend('PATCH', 'u03', "products/{$product->ref}", ['name' => 'X'], $this->lockOf('catalog_products', $product->id))->assertForbidden();
    $this->catPost('u03', "products/{$product->ref}/rates", ['rate_type' => 'Venta'])->assertForbidden();
    $this->catSend('PUT', 'u03', "products/{$product->ref}/channels/ecommerce", ['published' => true])->assertForbidden();

    $this->catGet('finance', "products/{$product->ref}/profitability")->assertOk();
    $this->catSend('PATCH', 'finance', "products/{$product->ref}", ['name' => 'X'], $this->lockOf('catalog_products', $product->id))->assertForbidden();
    $this->catSend('PUT', 'finance', "products/{$product->ref}/markup", ['markup_pct' => 30])->assertForbidden();
    $this->catGet('accounting', 'products')->assertForbidden();
    $this->catGet('u03', "products/{$product->ref}/profitability")->assertForbidden();
});

it('serialización por rol: Ventas sin cost/margin ni tarifas netas; Operaciones con cost sin margin', function () {
    $product = $this->makeProduct([], Product::ACTIVE);
    $sales = $this->catGet('u03', "products/{$product->ref}/rates")->assertOk()->json('data');
    expect($sales)->toHaveCount(1)->and($sales[0])->not->toHaveKey('cost')->not->toHaveKey('margin_pct');

    $ops = $this->catGet('ops', "products/{$product->ref}/rates")->assertOk()->json('data');
    expect($ops)->toHaveCount(1)->and($ops[0])->toHaveKey('cost')->not->toHaveKey('margin_pct');

    $finance = $this->catGet('finance', "products/{$product->ref}/rates")->assertOk()->json('data');
    expect($finance)->toHaveCount(2)->and($finance[0])->toHaveKey('margin_pct');

    $components = $this->catGet('u03', "products/{$product->ref}/components")->json('data');
    expect($components[0])->not->toHaveKey('unit_cost');
    $list = $this->catGet('u03', 'products')->json('data.0');
    expect($list)->not->toHaveKey('margin_pct');
    $tabs = array_keys($this->catGet('u03', "products/{$product->ref}")->json('data.tabs'));
    expect($tabs)->not->toContain('proveedores', 'b2b', 'rentabilidad', 'versiones', 'historial');
    $this->catGet('u03', "products/{$product->ref}/audit")->assertForbidden();
    $this->catGet('u03', 'providers')->assertForbidden();
});

it('listado: filtro por destino incluye descendientes y KPIs según rol', function () {
    $this->makeProduct([], Product::ACTIVE);
    $this->makeProduct(['primary_destination_id' => $this->dest['cusco']]);
    $this->catGet('manager', "products?filter[destination]={$this->dest['cusco']}")->assertOk()->assertJsonCount(2, 'data');
    $this->catGet('manager', "products?filter[destination]={$this->dest['machu']}")->assertJsonCount(1, 'data');
    $this->catGet('manager', 'products?filter[q]=machu')->assertJsonCount(2, 'data');
    $this->catGet('manager', 'products?filter[status]=ACTIVE')->assertJsonCount(1, 'data')->assertJsonPath('data.0.from_price.price', '380.00');

    expect($this->catGet('manager', 'products/kpis')->json('data'))->toHaveKey('avg_margin_pct')
        ->and($this->catGet('u03', 'products/kpis')->json('data'))->toHaveKey('bookable_today')->not->toHaveKey('avg_margin_pct');
});

it('exporta productos en CSV auditado sin margen para Ventas', function () {
    $this->makeProduct([], Product::ACTIVE);
    $csv = $this->as('u03')->postJson('/api/v1/exports', ['resource' => 'catalog.products'])->assertOk()->getContent();
    expect($csv)->toContain('Machu Picchu Full Day')->not->toContain('Margen');
    $this->as('u03')->postJson('/api/v1/exports', ['resource' => 'catalog.providers'])->assertForbidden();
});
