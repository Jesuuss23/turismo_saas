<?php

use App\Models\Ops\Incident;
use App\Services\Ops\IncidentService;

beforeEach(function () {
    $this->channelWorld();
});

/** Cotización enviada al cliente de u03 (el usuario `customer`). */
function sentQuote(object $t): string
{
    $code = $t->makeQuote('u03')['code'];
    $t->sendQuote('u03', $code)->assertOk();

    return $code;
}

function quoteHeaders(object $t, string $code): array
{
    return $t->quoteLock($code) + $t->idem();
}

it('T01: aislamiento — usuarios de canales del tenant B no ven agencias, pedidos, contenidos ni portales del tenant A', function () {
    $this->b2bReserve()->assertCreated();
    $this->webOrder();
    $other = $this->makeTenant('inka');
    $admin = $this->tokenFor($this->makeUser($other, 'tenant_admin'));
    expect($this->api($admin)->getJson('/api/v1/channels/b2b/agencies')->assertOk()->json('data'))->toBe([])
        ->and($this->api($admin)->getJson('/api/v1/channels/ecommerce/orders')->assertOk()->json('data'))->toBe([]);
    $this->api($admin)->getJson('/api/v1/channels/b2b/agencies/AGB-01')->assertNotFound();
    $customer = $this->tokenFor($this->makeUser($other, 'customer', ['client_id' => $this->clients['u03']->id]));
    $this->api($customer)->getJson('/api/v1/portal/client/home')->assertForbidden()->assertJsonPath('code', 'PCL-BR-01');
    $this->shop('GET', 'public/shop/products', [], ['X-Tenant-Slug' => 'inka'])->assertOk()->assertJsonPath('data', []);
});

it('T02/PCL-BR-01: el cliente solo ve sus cotizaciones; otra cuenta → 404; un usuario B2B no entra al portal del cliente', function () {
    $code = sentQuote($this);
    $this->qGet('customer', 'portal/client/quotes')->assertOk()->assertJsonPath('data.0.code', $code);
    $this->qGet('customer2', "portal/client/quotes/{$code}")->assertNotFound();
    $this->qGet('b2b1', 'portal/client/home')->assertForbidden()->assertJsonPath('code', 'PCL-BR-01');
    $this->qGet('customer', 'portal/client/home')->assertOk()->assertJsonPath('data.kpis.quotes_pending', 1)->assertJsonPath('data.advisor.name', 'U03');
});

it('T18: aprobar vencida → 422; sin aceptar condiciones → 422; aprobación con evidencia; rechazo exige motivo y comentario', function () {
    $code = sentQuote($this);
    $this->qGet('customer', "portal/client/quotes/{$code}")->assertOk()->assertJsonPath('data.status', 'vista')->assertJsonPath('data.can_respond', true);
    $this->qPost('customer', "portal/client/quotes/{$code}/approve", ['approver_name' => 'Ana'], quoteHeaders($this, $code))->assertStatus(422)->assertJsonValidationErrors('accept_terms');
    $this->qPost('customer', "portal/client/quotes/{$code}/approve", ['accept_terms' => true], quoteHeaders($this, $code))->assertStatus(422)->assertJsonValidationErrors('approver_name');
    $this->qPost('customer', "portal/client/quotes/{$code}/reject", ['reason' => 'Precio'], quoteHeaders($this, $code))->assertStatus(422)->assertJsonValidationErrors('comment');
    $this->qPost('customer', "portal/client/quotes/{$code}/reject", ['reason' => 'Otro motivo', 'comment' => 'x'], quoteHeaders($this, $code))->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->qPost('customer', "portal/client/quotes/{$code}/approve", ['approver_name' => 'Ana Gutiérrez', 'accept_terms' => true, 'note' => 'OK'], quoteHeaders($this, $code))
        ->assertOk()->assertJsonPath('data.status', 'aprobada')->assertJsonPath('data.approval.by_name', 'Ana Gutiérrez');
    $q = $this->admin()->table('quote_quotes')->where('code', $code)->first();
    expect($q->approval_channel)->toBe('Portal cliente')->and($q->approval_hash)->toStartWith('sha256:')->and($q->approval_ip)->not->toBeNull()
        ->and($this->admin()->table('quote_versions')->where('quote_id', $q->id)->value('status'))->toBe('aprobada');
    expect(collect($this->qGet('customer', 'portal/client/documents')->json('data'))->pluck('kind')->all())->toContain('Propuesta');

    $expired = sentQuote($this);
    $this->admin()->table('quote_quotes')->where('code', $expired)->update(['valid_until' => now()->subDay()->toDateString()]);
    $this->qPost('customer', "portal/client/quotes/{$expired}/approve", ['approver_name' => 'Ana', 'accept_terms' => true], quoteHeaders($this, $expired))
        ->assertStatus(422)->assertJsonPath('code', 'PCL-BR-03');
    $this->qPost('customer', "portal/client/quotes/{$expired}/update-request", [])->assertCreated()->assertJsonPath('data.category', 'Cotizaciones')
        ->assertJsonPath('data.owner', 'U03');
    $this->qPost('customer', "portal/client/quotes/{$expired}/reject", ['reason' => 'Precio', 'comment' => 'Muy caro'], quoteHeaders($this, $expired))->assertOk()
        ->assertJsonPath('data.status', 'rechazada');
});

it('T19: la previsualización interna no marca vista ni muta; Ventas solo su cartera', function () {
    $code = sentQuote($this);
    $client = $this->clients['u03']->id;
    $this->qGet('u03', "channels/portal-preview/clients/{$client}/quotes/{$code}")->assertOk()->assertJsonPath('data.status', 'enviada')->assertJsonPath('meta.preview', true);
    expect($this->admin()->table('quote_quotes')->where('code', $code)->value('status'))->toBe('enviada');
    $this->qGet('u04', "channels/portal-preview/clients/{$client}/home")->assertForbidden()->assertJsonPath('message', 'Registro fuera de su cartera');
    $this->qPost('u03', "portal/client/quotes/{$code}/approve", ['approver_name' => 'X', 'accept_terms' => true], quoteHeaders($this, $code))->assertForbidden();
    expect(collect($this->qGet('u03', 'channels/portal-preview/clients')->assertOk()->json('data'))->pluck('id')->all())->toBe([$client])
        ->and($this->qGet('manager', 'channels/portal-preview/clients')->assertOk()->json('data'))->toHaveCount(1);
    $this->qGet('ops', "channels/portal-preview/clients/{$client}/home")->assertForbidden();
});

it('T20: pago en línea — monto > saldo → 422 RT-9; rechazado mantiene la obligación; parcial deja la CxC parcial', function () {
    $res = $this->makeReservation('u03');
    $cxc = collect($this->qGet('customer', 'portal/client/receivables')->assertOk()->json('data'))->firstWhere('amount', '358.72');
    $pay = fn (string $amount, string $card = '4111111111111111') => $this->qPost('customer', "portal/client/receivables/{$cxc['id']}/payments",
        ['amount' => $amount, 'card' => ['number' => $card, 'expiry' => '12/29', 'cvv' => '123']], $this->idem());
    $pay('400.00')->assertStatus(422)->assertJsonPath('code', 'RT-9');
    $pay('100.00', '4000000000000002')->assertOk()->assertJsonPath('data.result', 'rechazado')->assertJsonPath('data.receivable.status', 'abierta');
    $pay('100.00')->assertCreated()->assertJsonPath('data.result', 'aprobado')->assertJsonPath('data.receivable.status', 'parcial')->assertJsonPath('data.receivable.balance', '258.72');
    $history = collect($this->qGet('customer', 'portal/client/payments')->assertOk()->json('data'));
    expect($history->pluck('status')->sort()->values()->all())->toBe(['aplicado', 'rechazado'])
        ->and($history->firstWhere('status', 'aplicado')['method'])->toBe('Tarjeta (portal cliente)')
        ->and($this->qGet('customer2', 'portal/client/receivables')->json('data'))->toBe([]);
    $this->qGet('customer', "portal/client/reservations/{$res['code']}")->assertOk()->assertJsonPath('data.receivables.0.status', 'parcial');
});

it('PCL-BR-09: la solicitud exige asunto y mensaje y se asigna al asesor del cliente', function () {
    $this->qPost('customer', 'portal/client/tickets', ['message' => 'Hola'])->assertStatus(422)->assertJsonValidationErrors('subject');
    $this->qPost('customer', 'portal/client/tickets', ['subject' => 'Duda', 'message' => 'Hola', 'category' => 'Pagos'])->assertCreated()
        ->assertJsonPath('data.owner', 'U03')->assertJsonPath('data.priority', 'alta');
    $this->qGet('customer', 'portal/client/tickets')->assertOk()->assertJsonCount(1, 'data');
    expect($this->admin()->table('notif_messages')->where('event', 'soporte.ticket')->value('user_id'))->toBe($this->users['u03']->id);
});

it('T21: pasajero — enlace con datos incorrectos → 422; enlace de un uso; ack idempotente con bitácora y aviso', function () {
    $res = $this->makeReservation('u03');
    $email = $this->clients['u03']->email;
    $this->shop('POST', 'portal/passenger/auth/link', ['email' => 'otro@correo.test', 'code' => $res['code']])->assertStatus(422)
        ->assertJsonPath('message', 'Correo o código de reserva no coinciden.');
    $link = $this->shop('POST', 'portal/passenger/auth/link', ['email' => strtoupper($email), 'code' => $res['code']])->assertOk()->json('data');
    $session = $this->shop('POST', 'portal/passenger/auth/verify', ['token' => $link['dev_token']])->assertOk()->json('data');
    $this->shop('POST', 'portal/passenger/auth/verify', ['token' => $link['dev_token']])->assertStatus(422);
    $this->tokens['pax'] = $session['token'];
    $this->qGet('pax', 'portal/passenger/trip')->assertOk()->assertJsonPath('data.reservation.code', $res['code']);

    $order = $this->makeOrder($res['id']);
    $incident = $this->inTenant(function () use ($order) {
        $i = app(IncidentService::class)->create(['order_id' => $order['id'], 'type' => 'Horario', 'severity' => 'media', 'title' => 'Proveedor adelanta recojo',
            'description' => 'Interno'], $this->users['ops']);
        $i->forceFill(['public_title' => 'Cambio de hora de recojo', 'public_message' => 'Lo recogeremos a las 06:30.'])->save();
        app(IncidentService::class)->create(['order_id' => $order['id'], 'type' => 'Proveedor', 'severity' => 'baja', 'title' => 'Interna', 'description' => 'No se muestra'], $this->users['ops']);

        return $i;
    }, 'ops');
    $alerts = $this->qGet('pax', 'portal/passenger/alerts')->assertOk()->json('data');
    expect($alerts)->toHaveCount(1)->and($alerts[0]['title'])->toBe('Cambio de hora de recojo')->and($alerts[0]['acknowledged'])->toBeFalse();
    $this->qPost('pax', "portal/passenger/alerts/{$incident->id}/ack")->assertOk()->assertJsonPath('data.already', false);
    $this->qPost('pax', "portal/passenger/alerts/{$incident->id}/ack")->assertOk()->assertJsonPath('data.already', true);
    expect($this->admin()->table('ops_incident_pax_acks')->count())->toBe(1)
        ->and($this->admin()->table('ops_incident_logs')->where('incident_id', $incident->id)->where('message', 'like', '%confirmó la lectura%')->count())->toBe(1)
        ->and(collect($this->auditRows('Incidencia', $incident->code))->pluck('action')->all())->toContain('lectura_pasajero')
        ->and($this->admin()->table('notif_messages')->where('event', 'alerta.leida')->count())->toBe(1);
    expect(Incident::ACTIVE)->toContain('abierta');
});

it('PPX-BR: el pasajero ve solo su viaje; vouchers auditados; mensaje y comentarios; acompañantes con tipo fijo', function () {
    $res = $this->makeReservation('u03');
    $pax = $this->addUser('pax', 'passenger', ['reservation_id' => $res['id'], 'client_id' => $this->clients['u03']->id]);
    $other = $this->makeReservation('u04');
    $this->qGet('pax', "portal/passenger/trip?reservation={$other['code']}")->assertOk()->assertJsonPath('data.reservation.code', $res['code']);
    $this->qPost('pax', 'portal/passenger/messages', ['topic' => 'Salud', 'message' => ''])->assertStatus(422);
    $this->qPost('pax', 'portal/passenger/messages', ['topic' => 'Salud', 'message' => 'Tengo mal de altura'])->assertCreated()->assertJsonPath('data.priority', 'alta');
    $this->qPost('pax', 'portal/passenger/feedback', ['type' => 'comentario', 'text' => 'ok'], $this->idem())->assertStatus(422)->assertJsonValidationErrors('text');
    $this->qPost('pax', 'portal/passenger/feedback', ['type' => 'reclamo', 'text' => 'El traslado llegó tarde', 'rating' => 2], $this->idem())->assertCreated()
        ->assertJsonPath('data.ticket', true);
    $this->qGet('pax', 'portal/passenger/feedback')->assertOk()->assertJsonPath('data.0.complaints_book', fn ($v) => str_contains($v, 'Ley 29571'));
    $companions = $this->qGet('pax', 'portal/passenger/companions')->assertOk()->json('data');
    $lead = collect($companions['passengers'])->firstWhere('lead', true) ?? $companions['passengers'][0];
    $headers = $this->resLock($res['code']);
    $this->as('pax')->withHeaders($headers)->putJson('/api/v1/portal/passenger/companions', ['passengers' => [['id' => $lead['id'], 'birth_date' => now()->subYears(5)->toDateString()]]])
        ->assertStatus(422)->assertJsonPath('code', 'CAN-V21-05');
    $this->as('pax')->withHeaders($headers)->putJson('/api/v1/portal/passenger/companions', ['passengers' => [['id' => $lead['id'], 'doc_number' => 'X998877',
        'birth_date' => now()->subYears(30)->toDateString()]]])->assertOk();
    expect($this->admin()->table('booking_passengers')->where('id', $lead['id'])->value('doc_number'))->toBe('X998877');
    $this->qGet('ops', "portal/passenger/trip?reservation={$res['code']}")->assertOk()->assertJsonPath('meta.preview', true);
    $this->qPost('ops', "portal/passenger/messages?reservation={$res['code']}", ['message' => 'x'])->assertForbidden();
    expect($pax->reservation_id)->toBe($res['id']);
});
