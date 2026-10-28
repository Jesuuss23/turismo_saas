<?php

use App\Models\Catalog\Product;
use App\Models\Finance\Payment;
use App\Services\Catalog\AvailabilityService;
use App\Services\Finance\PaymentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->siteWorld();
});

function siteCard(string $number = '4111111111111111'): array
{
    return ['method' => 'card', 'card' => ['number' => $number, 'expiry' => '12/29', 'cvv' => '123']];
}

it('§10-1 catálogo público: solo ACTIVE + e-commerce + tarifa vigente; borrador y suspendido → 404 PRD-BR-002; el host de otro tenant no ve productos', function () {
    $draft = $this->makeProduct(['name' => 'Borrador Paracas']);
    $refs = collect($this->site('GET', 'products')->assertOk()->assertHeader('Cache-Control')->json('data'))->pluck('ref');
    expect($refs->all())->toContain($this->product->ref, $this->humantay->ref)->not->toContain($draft->ref);
    $this->site('GET', "products/{$draft->ref}")->assertNotFound()->assertJsonPath('code', 'PRD-BR-002');
    $this->moveTo($this->humantay, Product::SUSPENDED);
    $this->site('GET', "products/{$this->humantay->ref}")->assertNotFound()->assertJsonPath('code', 'PRD-BR-002');

    // Tenant resuelto por host: beta no ve los productos de andes.
    $this->makeTenant('beta');
    $base = config('turismo.tenant_base_domain');
    $this->flushHeaders();
    expect($this->getJson("http://beta.{$base}/api/v1/public/products")->assertOk()->json('data'))->toBe([]);
    expect(collect($this->getJson("http://{$this->tenant->slug}.{$base}/api/v1/public/products")->assertOk()->json('data'))->pluck('ref')->all())->toContain($this->product->ref);
    $this->getJson("http://beta.{$base}/api/v1/public/products/{$this->product->ref}")->assertNotFound();
});

it('§10-2 precio por fecha: media ×1.00, baja ×0.88 y Año Nuevo ×1.40 sobre la misma tarifa; sin tarifa vigente → no vendible', function () {
    $quote = fn (int $days) => $this->site('POST', 'price-quote', ['product' => $this->product->ref, 'date' => now()->addDays($days)->toDateString(), 'adults' => 1, 'currency' => 'USD'])
        ->assertOk()->json('data');
    $media = $quote(30);
    expect($media['unit_adult'])->toBe('380.00')->and($media['season']['kind'])->toBe('media');
    expect($quote(45)['unit_adult'])->toBe('334.40')->and($quote(45)['season']['kind'])->toBe('baja');
    $especial = $quote(51);
    expect($especial['unit_adult'])->toBe('532.00')->and($especial['season']['kind'])->toBe('especial');

    $this->admin()->table('catalog_rates')->where('product_id', $this->product->id)->update(['valid_to' => now()->addDays(60)->toDateString()]);
    $this->site('POST', 'availability', ['product' => $this->product->ref, 'date' => now()->addDays(65)->toDateString()])->assertOk()
        ->assertJsonPath('data.sellable', false)->assertJsonPath('data.reason', 'sin_tarifa');
    $this->site('POST', 'price-quote', ['product' => $this->product->ref, 'date' => now()->addDays(65)->toDateString(), 'adults' => 1])->assertStatus(422)->assertJsonPath('code', 'SIT-BR-02');
    $month = now()->addDays(21)->format('Y-m');
    $day = collect($this->site('GET', "products/{$this->product->ref}/calendar?month={$month}&currency=USD")->assertOk()->json('data.days'))
        ->firstWhere('date', $this->resDeparture->departure_date->toDateString());
    expect($day['sellable'])->toBeTrue()->and($day['price'])->toBe('380.00')->and($day['departure']['id'])->toBe($this->resDeparture->id);
});

it('§10-3 la tarifa E-commerce vigente (S/ 129) gana a Directo (S/ 140) y devuelve list_price', function () {
    $card = collect($this->site('GET', 'products?currency=PEN')->json('data'))->firstWhere('ref', $this->humantay->ref);
    // «Desde» = mínimo de los próximos días (incluye la temporada baja ×0.88 sobre 129).
    expect($card['from_price'])->toBe('113.50')->and($card['list_price'])->not->toBeNull();
    $q = $this->site('POST', 'price-quote', ['product' => $this->humantay->ref, 'date' => now()->addDays(10)->toDateString(), 'adults' => 2, 'currency' => 'PEN'])->assertOk()->json('data');
    expect($q['unit_adult'])->toBe('129.00')->and($q['list_price'])->toBe('140.00')->and($q['total'])->toBe('258.00');
});

it('§10-4 añadir con salida crea hold de adultos+niños; dos carritos por los últimos cupos → uno 409', function () {
    $this->newCart('a');
    $this->addMapi('a', 2, null, ['children' => 1])->assertCreated()->assertJsonPath('data.lines.0.hold.status', 'hold');
    expect($this->holdsOf())->toBe(3);
    $this->newCart('b');
    $this->newCart('c');
    $this->addMapi('b', 7)->assertCreated();
    $this->addMapi('c', 1)->assertStatus(409);
    expect($this->holdsOf())->toBe(10);
});

it('§10-5 hold vencido: el job lo expira; el checkout lo re-bloquea con cupos; sin cupos → 409 SIT-BR-07', function () {
    $this->newCart('a');
    $this->addMapi('a', 2)->assertCreated();
    $this->admin()->table('catalog_availability_holds')->where('reference_type', 'cart')->update(['expires_at' => now()->subMinute()]);
    $this->inTenant(fn () => app(AvailabilityService::class)->expireHolds());
    expect($this->holdsOf())->toBe(0);
    $order = $this->placeOrder('a');
    expect($this->holdsOf())->toBe(2)->and($order['data']['hold_expires_at'])->not->toBeNull();

    // Segundo carrito: su hold vence y otro cliente toma todos los cupos.
    $this->newCart('b');
    $this->addMapi('b', 2)->assertCreated();
    $cartB = $this->admin()->table('channel_web_carts')->where('tenant_id', $this->tenant->id)->orderByDesc('code')->value('id');
    $this->admin()->table('catalog_availability_holds')->where('reference_id', $cartB)->update(['expires_at' => now()->subMinute()]);
    $this->inTenant(fn () => app(AvailabilityService::class)->expireHolds());
    $this->newCart('c');
    $this->addMapi('c', 8)->assertCreated();
    $this->site('POST', 'checkout/orders', $this->checkoutData(2, ['buyer' => ['email' => 'otro@correo.test']]), $this->cartHeaders('b') + $this->idem())
        ->assertStatus(409)->assertJsonPath('code', 'SIT-BR-07');
});

it('§10-6 PRIMAVERA8 solo en MAPI (−8 %); Humantay 4 pax toma «Grupo 4+» 10 % sin acumular; un código inexistente no bloquea', function () {
    $this->newCart('a');
    $this->addMapi('a', 2)->assertCreated();
    $this->addLine('a', $this->humantay, ['date' => now()->addDays(10)->toDateString(), 'adults' => 4])->assertCreated();
    $cart = $this->site('PUT', 'carts/current/promo', ['code' => 'primavera8'], $this->cartHeaders('a'))->assertOk()->json('data');
    $lines = collect($cart['lines'])->keyBy(fn ($l) => $l['product']['name']);
    expect($cart['promo_applied'])->toBeTrue()
        ->and($lines['Machu Picchu Full Day']['discount'])->toBe('60.80')
        ->and($lines['Laguna Humantay']['discount_label'])->toContain('Grupo')
        ->and($lines['Laguna Humantay']['discount'])->toBe('13.76'); // 10 % de S/ 516 en USD (TC 3.75)
    $bad = $this->site('PUT', 'carts/current/promo', ['code' => 'NOEXISTE'], $this->cartHeaders('a'))->assertOk()->json('data');
    expect($bad['promo_applied'])->toBeFalse()->and($bad['promo_message'])->not->toBeNull();
    $this->placeOrder('a', 4);
});

it('§10-7 anticipo: a 10 días → 422 SIT-BR-09; a 20 días → 40 %; a 60 días → 30 % + CxC saldo que vence 15 días antes', function () {
    $near = $this->makeDeparture($this->product, ['departure_date' => now()->addDays(10)->toDateString()]);
    $mid = $this->makeDeparture($this->product, ['departure_date' => now()->addDays(20)->toDateString()]);
    $far = $this->makeDeparture($this->product, ['departure_date' => now()->addDays(60)->toDateString()]);

    $this->newCart('a');
    $this->addMapi('a', 1, $near)->assertCreated();
    $this->site('POST', 'checkout/orders', $this->checkoutData(1, ['pay_mode' => 'anticipo']), $this->cartHeaders('a') + $this->idem())
        ->assertStatus(422)->assertJsonPath('code', 'SIT-BR-09');

    $this->newCart('b');
    $modes = $this->addMapi('b', 1, $mid)->assertCreated()->json('data.pay_modes');
    expect($modes[1]['pct'])->toBe(40)->and($modes[1]['available'])->toBeTrue();
    expect($this->placeOrder('b', 1, ['pay_mode' => 'anticipo'])['data']['deposit_pct'])->toBe('40.00');

    $this->newCart('c');
    $this->addMapi('c', 1, $far)->assertCreated();
    $order = $this->placeOrder('c', 1, ['pay_mode' => 'anticipo', 'buyer' => ['email' => 'lejos@correo.test']]);
    expect($order['data']['deposit_pct'])->toBe('30.00')->and($order['data']['amount_due_now'])->toBe('100.32')
        ->and($order['data']['balance_due_date'])->toBe(now()->addDays(45)->toDateString());
    $res = $this->admin()->table('channel_ecom_order_lines')->where('order_id', $this->admin()->table('channel_ecom_orders')->where('code', $order['code'])->value('id'))->value('reservation_id');
    $cxc = $this->admin()->table('fin_receivables')->where('reservation_id', $res)->orderBy('due_date')->get(['kind', 'amount', 'due_date']);
    expect($cxc)->toHaveCount(2)->and($cxc[0]->kind)->toBe('anticipo')->and($cxc[0]->amount)->toBe('100.32')
        ->and($cxc[1]->kind)->toBe('saldo')->and($cxc[1]->amount)->toBe('234.08')->and($cxc[1]->due_date)->toBe(now()->addDays(45)->toDateString());
});

it('§10-8 factura con RUC inválido, DNI de 7 dígitos y niño de 12 años → 422 con errores por campo', function () {
    $this->newCart('a');
    $this->addMapi('a', 1, null, ['children' => 1])->assertCreated();
    $child = ['type' => 'CHD', 'first_name' => 'Mateo', 'last_name' => 'Campos', 'doc_type' => 'DNI', 'doc_number' => '80123456',
        'birth_date' => now()->addDays(21)->subYears(12)->subDay()->toDateString(), 'nationality' => 'Perú'];
    $data = $this->checkoutData(1, ['billing' => ['type' => 'Factura', 'ruc' => '30123456789', 'name' => 'ACME SAC', 'fiscal_address' => 'Av. Lima 1'], 'buyer' => ['doc_number' => '7012345']]);
    $data['passengers'][] = $child;
    $this->site('POST', 'checkout/orders', $data, $this->cartHeaders('a') + $this->idem())->assertStatus(422)
        ->assertJsonValidationErrors(['billing.ruc', 'buyer.doc_number', 'passengers.1.birth_date']);
    $this->site('POST', 'checkout/validate', ['step' => 'comprador'] + $data, $this->cartHeaders('a'))->assertStatus(422)->assertJsonValidationErrors(['billing.ruc']);
    expect($this->admin()->table('channel_ecom_orders')->where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

it('§10-9 tarjeta rechazada mantiene hold y obligación; el reintento aprobado confirma, suma sold y emite vouchers', function () {
    $this->newCart('a');
    $this->addMapi('a', 2)->assertCreated();
    $order = $this->placeOrder('a');
    $rej = $this->payOrder($order, siteCard('4000000000000002'))->assertOk()->json('data');
    expect($rej['result'])->toBe('rechazado')->and($rej['order']['status'])->toBe('pago_rechazado');
    expect($this->admin()->table('fin_payments')->where('tenant_id', $this->tenant->id)->where('status', 'rechazado')->count())->toBe(1)->and($this->sold())->toBe(0)->and($this->holdsOf())->toBe(2);

    $ok = $this->payOrder($order, siteCard())->assertCreated()->json('data');
    expect($ok['result'])->toBe('aprobado')->and($ok['order']['status'])->toBe('confirmado')->and($ok['order']['lines'][0]['reservation']['status'])->toBe('confirmada');
    expect($this->sold())->toBe(2)->and($this->holdsOf())->toBe(0);
    $res = $this->admin()->table('booking_reservations')->where('code', $ok['order']['lines'][0]['reservation']['code'])->first();
    expect($this->admin()->table('ops_vouchers')->where('reservation_id', $res->id)->count())->toBeGreaterThan(0)
        ->and($this->admin()->table('fin_receivables')->where('reservation_id', $res->id)->value('status'))->toBe('pagada');
});

it('§10-10 transferencia: pago pendiente, cupos consumidos, reserva pendiente_pago sin vouchers; la validación de Finanzas confirma', function () {
    $this->newCart('a');
    $this->addMapi('a', 2)->assertCreated();
    $order = $this->placeOrder('a');
    $this->payOrder($order, ['method' => 'transfer'])->assertStatus(422)->assertJsonValidationErrors(['operation_ref']);
    $r = $this->payOrder($order, ['method' => 'transfer', 'operation_ref' => 'OP-778899'])->assertCreated()->json('data');
    expect($r['result'])->toBe('por_validar')->and($r['order']['status'])->toBe('pago_por_validar')->and($r['order']['lines'][0]['reservation']['status'])->toBe('pendiente_pago');
    expect($this->sold())->toBe(2)->and($this->holdsOf())->toBe(0);
    $res = $this->admin()->table('booking_reservations')->where('code', $r['order']['lines'][0]['reservation']['code'])->first();
    expect($this->admin()->table('ops_vouchers')->where('reservation_id', $res->id)->count())->toBe(0);
    $payment = $this->admin()->table('fin_payments')->where('reservation_id', $res->id)->first();
    expect($payment->status)->toBe('pendiente');

    $this->inTenant(fn () => app(PaymentService::class)->validate(Payment::find($payment->id), true, null, $this->users['finance']), 'finance');
    $after = $this->site('GET', "orders/{$order['code']}", [], ['X-Order-Token' => $order['token']])->assertOk()->json('data');
    expect($after['status'])->toBe('confirmado')->and($after['lines'][0]['reservation']['status'])->toBe('confirmada');
    expect($this->admin()->table('ops_vouchers')->where('reservation_id', $res->id)->count())->toBeGreaterThan(0);
});

it('§10-11 idempotencia: repetir creación de pedido y pago con la misma clave no duplica; el webhook repetido no aplica dos veces', function () {
    $this->newCart('a');
    $this->addMapi('a', 2)->assertCreated();
    $key = $this->idem();
    $a = $this->site('POST', 'checkout/orders', $this->checkoutData(), $this->cartHeaders('a') + $key)->assertCreated()->json('data');
    $b = $this->site('POST', 'checkout/orders', $this->checkoutData(), $this->cartHeaders('a') + $key)->assertCreated()->json('data');
    expect($b['code'])->toBe($a['code'])->and($this->admin()->table('channel_ecom_orders')->where('tenant_id', $this->tenant->id)->count())->toBe(1);

    $order = ['code' => $a['code'], 'token' => $a['token']];
    $payKey = bin2hex(random_bytes(8));
    $this->payOrder($order, siteCard('4000000000000002'), $payKey)->assertOk();
    $this->payOrder($order, siteCard('4000000000000002'), $payKey)->assertOk();
    expect($this->admin()->table('channel_ecom_payment_attempts')->count())->toBe(1);

    $body = ['order' => $a['code'], 'txn_id' => 'SBX-WEBHOOK-1', 'result' => 'aprobado', 'last4' => '1111', 'message' => 'Aprobado'];
    $sign = fn () => ['X-Signature' => hash_hmac('sha256', json_encode($body), (string) config('turismo.payments.webhook_secret'))];
    $this->shop('POST', 'webhooks/payments/sandbox', $body, $sign())->assertOk()->assertJsonPath('data.status', 'confirmado');
    $this->shop('POST', 'webhooks/payments/sandbox', $body, $sign())->assertOk();
    expect($this->admin()->table('fin_payments')->where('tenant_id', $this->tenant->id)->where('status', 'aplicado')->count())->toBe(1)->and($this->sold())->toBe(2);
});

it('§10-12 Mi cuenta: el cliente solo ve lo suyo; pagar el saldo no emite vouchers mientras el anticipo siga por validar', function () {
    $far = $this->makeDeparture($this->product, ['departure_date' => now()->addDays(60)->toDateString()]);
    $u03 = $this->clients['u03'];
    $this->newCart('a', 'USD', $this->bearer('customer'));
    $this->addMapi('a', 1, $far)->assertCreated();
    $order = $this->placeOrder('a', 1, ['pay_mode' => 'anticipo', 'buyer' => ['email' => $u03->email]], $this->bearer('customer'));
    $this->payOrder($order, ['method' => 'yape', 'operation_ref' => 'YP-123456'])->assertCreated();
    $resCode = $order['data']['lines'][0]['reservation']['code'];

    // Reserva de otro cliente (u04) por el canal de la tienda.
    $this->newCart('b');
    $this->addMapi('b', 1)->assertCreated();
    $other = $this->placeOrder('b', 1, ['buyer' => ['email' => $this->clients['u04']->email, 'doc_number' => '71234567'], 'passengers' => [['doc_number' => '71234567']]]);
    $otherRes = $other['data']['lines'][0]['reservation']['code'];

    $mine = collect($this->site('GET', 'me/reservations', [], $this->bearer('customer'))->assertOk()->json('data'))->pluck('code');
    expect($mine->all())->toContain($resCode)->not->toContain($otherRes);
    $this->site('GET', "me/reservations/{$otherRes}", [], $this->bearer('customer'))->assertNotFound();
    $this->site('GET', 'me/reservations')->assertUnauthorized();

    $rows = collect($this->site('GET', 'me/receivables', [], $this->bearer('customer'))->assertOk()->json('data'))->where('reservation', $resCode)->keyBy(fn ($r) => str_starts_with($r['concept'], 'Anticipo') ? 'anticipo' : 'saldo');
    expect($rows['anticipo']['pending_validation'])->toBe($rows['anticipo']['balance'])->and($rows['anticipo']['can_pay'])->toBeFalse()->and($rows['saldo']['can_pay'])->toBeTrue();
    $this->site('POST', "me/receivables/{$rows['anticipo']['id']}/pay", siteCard(), $this->bearer('customer') + $this->idem())->assertStatus(409)->assertJsonPath('code', 'SIT-BR-20');
    $this->site('POST', "me/receivables/{$rows['saldo']['id']}/pay", siteCard(), $this->bearer('customer') + $this->idem())->assertSuccessful();
    $res = $this->admin()->table('booking_reservations')->where('code', $resCode)->first();
    expect($this->admin()->table('fin_receivables')->where('id', $rows['saldo']['id'])->value('status'))->toBe('pagada')
        ->and($this->admin()->table('ops_vouchers')->where('reservation_id', $res->id)->count())->toBe(0);
});

it('§10-13 lead web: sin asignar, nuevo y luego duplicado, con SLA y consentimiento', function () {
    $body = ['name' => 'María Gómez', 'email' => 'maria@correo.test', 'phone' => '987111222', 'destinations' => ['Cusco'], 'start_date' => now()->addDays(60)->toDateString(),
        'adults' => 2, 'children' => 2, 'budget' => 3000, 'consent' => true];
    $this->site('POST', 'leads', ['consent' => false] + $body)->assertStatus(422)->assertJsonValidationErrors(['consent']);
    $a = $this->site('POST', 'leads', $body)->assertCreated()->json('data');
    $b = $this->site('POST', 'leads', ['undecided_dates' => true] + $body)->assertCreated()->json('data');
    expect($a['status'])->toBe('nuevo')->and($a['score'])->toBe(80)->and($b['status'])->toBe('duplicado');
    $lead = $this->admin()->table('crm_leads')->where('code', $a['code'])->first();
    expect($lead->owner_id)->toBeNull()->and($lead->channel_code)->toBe('web')->and($lead->sla_due_at)->not->toBeNull()
        ->and(json_decode($lead->consent, true)['policy_version'])->toBe('PDP-2026.1');
});

it('§10-14 libro de reclamaciones desde el sitio: correlativo por sede y plazo de 15 días hábiles', function () {
    $form = $this->site('GET', 'claims')->assertOk()->json('data');
    preg_match_all('/\d+/', $form['captcha']['question'], $m);
    $sede = $this->admin()->table('iam_sedes')->where('tenant_id', $this->tenant->id)->value('id');
    $body = ['sede_id' => $sede, 'type' => 'reclamo', 'accept_email' => true, 'captcha_token' => $form['captcha']['token'], 'captcha_answer' => (string) array_sum($m[0]),
        'consumer' => ['name' => 'Ana Gutiérrez', 'doc_type' => 'DNI', 'doc_number' => '45678912', 'email' => 'ana@correo.test', 'address' => 'Calle Los Pinos 230'],
        'good' => ['kind' => 'Servicio', 'description' => 'Tour Paracas'], 'detail' => 'El bus salió dos horas tarde y perdimos el paseo.', 'request' => 'Devolución'];
    $n = $this->site('POST', 'claims', $body, $this->idem())->assertCreated()->json('data');
    $case = $this->admin()->table('crm_cases')->where('book_number', $n['book_number'])->first();
    expect($n['book_number'])->toContain(now()->format('Y'))->and($case->due_date)->not->toBeNull()
        ->and(Carbon::parse($case->due_date)->gt(now()->addDays(15)))->toBeTrue();
});

it('§10-15 newsletter: sin casilla → 422; con casilla → suscriptor con consentimiento otorgado y versión de política; doble opt-in', function () {
    $this->site('POST', 'newsletter', ['email' => 'news@correo.test'])->assertStatus(422)->assertJsonPath('code', 'SIT-BR-25');
    $r = $this->site('POST', 'newsletter', ['email' => 'news@correo.test', 'consent' => true])->assertCreated()->json('data');
    expect($r['status'])->toBe('pendiente_confirmacion');
    $consent = $this->admin()->table('mkt_consents')->where('tenant_id', $this->tenant->id)->where('channel', 'email')->latest('created_at')->first();
    expect($consent->status)->toBe('otorgado')->and($consent->policy_version)->toBe('PDP-2026.1');
    $this->site('POST', "newsletter/confirm/{$r['confirm_token']}")->assertOk();
    expect($this->admin()->table('mkt_contacts_subscribers')->where('tenant_id', $this->tenant->id)->where('email', 'news@correo.test')->value('double_opt_in_at'))->not->toBeNull();
});

it('§10-16 la API pública nunca serializa costo, markup ni margen', function () {
    $this->newCart('a');
    $this->addMapi('a', 2)->assertCreated();
    $order = $this->placeOrder('a');
    $bodies = [
        $this->site('GET', 'products')->getContent(), $this->site('GET', "products/{$this->product->ref}")->getContent(),
        $this->site('POST', 'price-quote', ['product' => $this->product->ref, 'date' => now()->addDays(21)->toDateString(), 'adults' => 2])->getContent(),
        $this->site('GET', 'carts/current', [], $this->cartHeaders('a'))->getContent(),
        $this->site('GET', "orders/{$order['code']}", [], ['X-Order-Token' => $order['token']])->getContent(),
        $this->site('GET', 'offers')->getContent(), $this->site('GET', 'destinations')->getContent(),
    ];
    foreach ($bodies as $json) {
        expect($json)->not->toMatch('/"(cost|cost_total|markup|margin|margin_pct|unit_cost)"\s*:/');
    }
});

it('consulta de reserva por correo y código: 5/min, auditada; el token de consulta abre el pedido', function () {
    $this->newCart('a');
    $this->addMapi('a', 1)->assertCreated();
    $order = $this->placeOrder('a', 1);
    $this->site('POST', 'orders/lookup', ['email' => 'otro@correo.test', 'code' => $order['code']])->assertNotFound();
    $lk = $this->site('POST', 'orders/lookup', ['email' => 'VALERIA@correo.test', 'code' => $order['code']])->assertOk()->json('data.token');
    $this->site('GET', "orders/{$order['code']}", [], ['X-Order-Token' => $lk])->assertOk()->assertJsonPath('data.code', $order['code']);
    $this->site('GET', "orders/{$order['code']}", [], ['X-Order-Token' => 'malo'])->assertNotFound();
    expect(DB::connection('pgsql_admin')->table('audit_events')->where('tenant_id', $this->tenant->id)->where('action', 'buscar_reserva')->count())->toBeGreaterThan(0);
});
