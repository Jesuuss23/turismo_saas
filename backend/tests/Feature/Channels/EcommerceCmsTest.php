<?php

use App\Exceptions\ApiException;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Product;
use App\Services\Channels\CmsService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->channelWorld();
});

function promo(object $t, string $ref, array $data): void
{
    $t->qPost('manager', "channels/ecommerce/products/{$ref}/promotions", $data + ['date_from' => now()->toDateString(),
        'date_to' => now()->addMonth()->toDateString()])->assertCreated();
}

it('T11: ECO-BR-01 — no se publica un producto en revisión ni uno sin tarifa vigente', function () {
    $draft = $this->makeProduct(['name' => 'En revisión'], Product::REVIEW);
    $this->qPost('manager', "channels/ecommerce/products/{$draft->ref}/publish")->assertStatus(422)->assertJsonPath('code', 'ECO-BR-01');
    $this->admin()->table('catalog_rates')->where('product_id', $this->product->id)->update(['valid_from' => now()->subDays(9)->toDateString(), 'valid_to' => now()->subDays(2)->toDateString()]);
    $this->qPost('manager', "channels/ecommerce/products/{$this->product->ref}/publish")->assertStatus(422)->assertJsonPath('message', 'El producto no tiene tarifa vigente.');
    $this->shop('GET', "public/shop/products/{$this->product->ref}")->assertNotFound()->assertJsonPath('message', 'Este producto no está disponible para la venta en línea.');
});

it('T12: ECO-BR-05 — código inválido bloquea; 4 pax con código 8 % y automática 10 % aplica solo la mayor', function () {
    promo($this, $this->product->ref, ['name' => 'Primavera', 'value' => 8, 'promo_code' => 'PRIMAVERA8']);
    promo($this, $this->product->ref, ['name' => 'Grupo', 'value' => 10, 'min_pax' => 4]);
    $price = fn (array $d) => $this->shop('POST', 'public/shop/price', $d + ['product' => $this->product->ref, 'departure_id' => $this->resDeparture->id])->assertOk()->json('data');
    expect($price(['adults' => 1, 'code' => 'NOEXISTE'])['promo_state'])->toBe('bad');
    $two = $price(['adults' => 2, 'code' => 'primavera8']);
    expect($two['promo_state'])->toBe('ok')->and($two['discount'])->toBe('60.80')->and($two['total'])->toBe('699.20');
    $four = $price(['adults' => 4, 'code' => 'PRIMAVERA8']);
    expect($four['subtotal'])->toBe('1520.00')->and($four['discount'])->toBe('152.00')->and($four['discount_label'])->toContain('Grupo');
    $this->shop('POST', 'public/shop/orders', ['product' => $this->product->ref, 'departure_id' => $this->resDeparture->id, 'adults' => 1, 'code' => 'MALO',
        'buyer' => $this->buyer(), 'passengers' => []], $this->idem())->assertStatus(422)->assertJsonPath('code', 'ECO-BR-05');
});

it('T13: tarjeta rechazada mantiene cupos y obligación; el reintento aprobado confirma, paga la CxC y emite voucher', function () {
    $order = $this->webOrder(2);
    expect($this->sold())->toBe(2);
    $this->shop('POST', "public/shop/orders/{$order['code']}/payments", $this->card('4000000000000002'), ['X-Order-Token' => $order['token']] + $this->idem())
        ->assertOk()->assertJsonPath('data.result', 'rechazado')->assertJsonPath('data.order.status', 'pago_rechazado');
    $res = $this->admin()->table('booking_reservations')->where('channel', 'E-commerce')->first();
    expect($this->sold())->toBe(2)->and($res->status)->toBe(Reservation::PENDING_PAYMENT)
        ->and($this->admin()->table('fin_payments')->where('reservation_id', $res->id)->value('status'))->toBe('rechazado');
    $done = $this->shop('POST', "public/shop/orders/{$order['code']}/payments", $this->card('5555555555554444'), ['X-Order-Token' => $order['token']] + $this->idem())
        ->assertCreated()->assertJsonPath('data.order.status', 'confirmado')->json('data.order');
    expect($done['reservation']['status'])->toBe(Reservation::CONFIRMED)->and($done['vouchers'])->not->toBeEmpty()
        ->and($this->admin()->table('fin_receivables')->where('reservation_id', $res->id)->value('status'))->toBe('pagada')
        ->and($this->admin()->table('channel_ecom_payment_attempts')->pluck('card_masked')->all())->toBe(['•••• 0002', '•••• 4444']);
    $this->shop('GET', "public/shop/orders/{$order['code']}?t=otro")->assertNotFound();
});

it('T14: webhook firmado e idempotente por gateway_txn_id; firma inválida → 401', function () {
    $order = $this->webOrder();
    $body = json_encode(['order' => $order['code'], 'txn_id' => 'GW-1', 'result' => 'aprobado', 'last4' => '1111', 'code' => '00', 'message' => 'OK']);
    $sign = fn (string $b) => hash_hmac('sha256', $b, config('turismo.payments.webhook_secret'));
    $send = fn (string $sig) => $this->call('POST', '/api/v1/webhooks/payments/sandbox', [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SIGNATURE' => $sig, 'HTTP_X_TENANT_SLUG' => 'andes'], $body);
    $send('mala')->assertStatus(401);
    $send($sign($body))->assertOk()->assertJsonPath('data.status', 'confirmado');
    $send($sign($body))->assertOk();
    $res = $this->admin()->table('booking_reservations')->where('channel', 'E-commerce')->value('id');
    expect($this->admin()->table('fin_payments')->where('reservation_id', $res)->count())->toBe(1)
        ->and($this->admin()->table('ops_vouchers')->where('reservation_id', $res)->count())->toBe(2)
        ->and($this->admin()->table('channel_ecom_payment_attempts')->count())->toBe(1);
});

it('T15: ECO-BR-11 — al vencer el hold sin pago el pedido expira y libera cupos', function () {
    $order = $this->webOrder(3);
    expect($this->sold())->toBe(3);
    $this->admin()->table('channel_ecom_orders')->where('code', $order['code'])->update(['hold_expires_at' => now()->subMinute()]);
    Artisan::call('channels:run', ['mode' => 'holds']);
    expect($this->admin()->table('channel_ecom_orders')->where('code', $order['code'])->value('status'))->toBe('expirado')
        ->and($this->sold())->toBe(0)
        ->and($this->admin()->table('booking_reservations')->where('channel', 'E-commerce')->value('status'))->toBe(Reservation::CANCELLED)
        ->and($this->admin()->table('fin_receivables')->where('kind', 'saldo')->orderByDesc('created_at')->value('status'))->toBe('cancelada');
});

it('T16: ECO-BR-14 — anular confirmado → 409; anular pendiente libera cupos y cancela la reserva', function () {
    $paid = $this->webOrder();
    $this->shop('POST', "public/shop/orders/{$paid['code']}/payments", $this->card(), ['X-Order-Token' => $paid['token']] + $this->idem())->assertCreated();
    $lock = fn (string $code) => ['If-Match' => (string) $this->admin()->table('channel_ecom_orders')->where('code', $code)->value('lock_version')];
    $this->qPost('manager', "channels/ecommerce/orders/{$paid['code']}/cancel", ['reason' => 'x'], $lock($paid['code']))->assertStatus(409)->assertJsonPath('code', 'ECO-BR-14');
    $this->qPost('manager', "channels/ecommerce/orders/{$paid['code']}/payment-link", [], $this->idem())->assertStatus(409);
    $open = $this->webOrder(2);
    expect($this->sold())->toBe(3);
    $this->qPost('manager', "channels/ecommerce/orders/{$open['code']}/payment-link", [], $this->idem())->assertOk()->assertJsonPath('data.sent_to', 'valeria@correo.test');
    $this->qPost('manager', "channels/ecommerce/orders/{$open['code']}/cancel", [], $lock($open['code']))->assertStatus(422);
    $this->qPost('manager', "channels/ecommerce/orders/{$open['code']}/cancel", ['reason' => 'Cliente desiste'], $lock($open['code']))->assertOk()->assertJsonPath('data.status', 'cancelado');
    expect($this->sold())->toBe(1);
    $this->qGet('manager', 'channels/ecommerce/summary')->assertOk()->assertJsonPath('data.orders', 2)->assertJsonPath('data.confirmed', 1);
});

it('ECO-BR-08: datos del comprador y acompañantes obligatorios; ECO-BR-15: promoción válida', function () {
    $base = ['product' => $this->product->ref, 'departure_id' => $this->resDeparture->id, 'adults' => 2];
    $this->shop('POST', 'public/shop/orders', $base + ['buyer' => $this->buyer(['email' => 'malo']), 'passengers' => [['full_name' => 'A', 'doc_number' => '1']]], $this->idem())
        ->assertStatus(422)->assertJsonValidationErrors('buyer.email');
    $this->shop('POST', 'public/shop/orders', $base + ['buyer' => $this->buyer(['accept_privacy' => false]), 'passengers' => [['full_name' => 'A', 'doc_number' => '1']]], $this->idem())
        ->assertStatus(422)->assertJsonValidationErrors('buyer.accept_privacy');
    $this->shop('POST', 'public/shop/orders', $base + ['buyer' => $this->buyer(), 'passengers' => []], $this->idem())->assertStatus(422)->assertJsonValidationErrors('passengers');
    $this->qPost('manager', "channels/ecommerce/products/{$this->product->ref}/promotions", ['name' => 'X', 'value' => 60, 'date_from' => now()->toDateString(),
        'date_to' => now()->addDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('value');
    $this->qPost('manager', "channels/ecommerce/products/{$this->product->ref}/promotions", ['name' => 'X', 'value' => 5, 'promo_code' => 'con espacio',
        'date_from' => now()->toDateString(), 'date_to' => now()->addDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('promo_code');
    expect($this->shop('GET', 'public/shop/products')->assertOk()->json('data.0.ref'))->toBe($this->product->ref);
    $this->shop('GET', "public/shop/products/{$this->product->ref}")->assertOk()->assertJsonPath('data.by_departure', true)
        ->assertJsonPath('data.departures.0.id', $this->resDeparture->id);
});

it('T17: CMS — sin permiso de publicar no aprueba; editar publicado exige permiso; SEO, slug y motivo obligatorios', function () {
    $this->addUser('editor', 'sales');
    $c = $this->qPost('manager', 'cms/contents', ['title' => 'Quiénes somos', 'type' => 'Página', 'channel' => 'Web'])->assertCreated()
        ->assertJsonPath('data.slug', 'quienes-somos')->assertJsonPath('data.status', 'borrador')->json('data');
    $this->qPost('manager', 'cms/contents', ['title' => 'Otro', 'slug' => 'quienes-somos', 'type' => 'Página', 'channel' => 'Web'])->assertStatus(422)
        ->assertJsonPath('errors.slug.0', 'La URL /quienes-somos ya está en uso.');
    $lock = fn () => ['If-Match' => (string) $this->admin()->table('cms_contents')->where('id', $c['id'])->value('lock_version')];
    $this->qPost('manager', "cms/contents/{$c['id']}/submit", [], $lock())->assertOk()->assertJsonPath('data.status', 'en_revision');
    $this->qPost('manager', "cms/contents/{$c['id']}/return", [], $lock())->assertStatus(422);
    $this->qPost('manager', "cms/contents/{$c['id']}/approve", [], $lock())->assertOk();
    $this->qPost('manager', "cms/contents/{$c['id']}/publish", [], $lock())->assertStatus(422)->assertJsonPath('errors.seo_title.0', 'Complete el título SEO antes de publicar.');
    $this->as('manager')->withHeaders($lock())->patchJson("/api/v1/cms/contents/{$c['id']}", ['seo_title' => 'Andes Travel'])->assertOk();
    $this->qPost('manager', "cms/contents/{$c['id']}/publish", [], $lock())->assertOk()->assertJsonPath('data.status', 'publicado');
    $this->shop('GET', 'public/cms/contents/quienes-somos')->assertOk()->assertJsonPath('data.seo.title', 'Andes Travel');
    $this->qPost('manager', "cms/contents/{$c['id']}/unpublish", ['reason' => 'Actualizar'], $lock())->assertOk();
    $this->shop('GET', 'public/cms/contents/quienes-somos')->assertNotFound();
    expect(collect($this->qGet('manager', "cms/contents/{$c['id']}")->json('data.events'))->pluck('reason')->filter()->values()->all())->toBe(['Actualizar']);
    $this->qGet('editor', 'cms/contents')->assertForbidden();
});

it('CMS-BR-01/02 en el servicio: sin channels.cms.publish solo se redacta y envía a revisión', function () {
    $svc = app(CmsService::class);
    $this->addUser('writer', 'operations');
    $this->inTenant(function () use ($svc) {
        $c = $svc->create(['title' => 'Nota', 'type' => 'Artículo', 'channel' => 'Web'], $this->users['writer'], true);
        expect(fn () => $svc->approve($c, [], $this->users['writer']))->toThrow(ApiException::class, 'channels.cms.publish');
        $c = $svc->approve($c, [], $this->users['manager']);
        $c->forceFill(['seo_title' => 'Nota'])->save();
        $c = $svc->publish($c, $this->users['manager']);
        expect(fn () => $svc->update($c, ['title' => 'Cambio'], $this->users['writer']))->toThrow(ApiException::class, 'despublique');
    });
});
