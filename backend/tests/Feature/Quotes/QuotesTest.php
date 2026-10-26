<?php

use App\Models\Catalog\Product;
use App\Services\Catalog\RateService;
use App\Services\Crm\Linked\LinkedData;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->quoteWorld();
});

it('PROC-021: Ventas crea v1 borrador con precio sugerido, snapshot y políticas; la oportunidad pasa a cotización', function () {
    $opp = $this->makeOpportunity('u03', $this->clients['u03']);
    $quote = $this->makeQuote('u03', ['opportunity_id' => $opp->id]);

    expect($quote['code'])->toBe('COT-'.now()->year.'-00192')
        ->and($quote['status'])->toBe('borrador')
        ->and($quote['version']['items'][0]['unit_price'])->toBe('380.00')
        ->and($quote['version']['items'][0]['qty'])->toBe(1)
        ->and($quote['version']['items'][0]['pax'])->toBe(2)
        ->and($quote['version']['totals'])->toMatchArray(['sale' => '760.00', 'tax' => '136.80', 'total' => '896.80', 'cost' => '440.00', 'margin_pct' => '42.11'])
        ->and($quote['version']['snapshot_label'])->toStartWith($this->product->ref.' v')
        ->and($quote['internal_approval']['state'])->toBe('no_requerida')
        ->and($quote['client'])->toHaveKeys(['email', 'phone', 'country'])
        ->and($quote['opportunity']['title'])->toBe($opp->title);
    expect($this->fresh('crm_opportunities', $opp->id)->stage)->toBe('cotizacion');
    expect(collect($this->auditRows('Cotización', $quote['code']))->pluck('action')->all())->toContain('crear');
});

it('T-Q02: el tenant B no lista ni abre cotizaciones del tenant A (RLS → 404)', function () {
    $quote = $this->makeQuote();
    $other = $this->makeTenant('inka');
    $token = $this->tokenFor($this->makeUser($other, 'tenant_admin'));

    expect($this->api($token)->getJson('/api/v1/quotes')->assertOk()->json('data'))->toBe([]);
    $this->api($token)->getJson("/api/v1/quotes/{$quote['code']}")->assertNotFound();
    $this->api($token)->getJson("/api/v1/quotes/{$quote['id']}")->assertNotFound();
});

it('T-Q03: Ventas U04 no ve la cotización de U03 en lista, detalle ni búsqueda; el manager sí', function () {
    $quote = $this->makeQuote('u03');

    expect(collect($this->qGet('u04', 'quotes')->assertOk()->json('data'))->pluck('code'))->not->toContain($quote['code']);
    $this->qGet('u04', "quotes/{$quote['code']}")->assertForbidden()->assertJsonPath('code', 'SCOPE_OWNERSHIP');
    expect(collect($this->qGet('u04', 'search?q='.$quote['code'])->json('data'))->where('type', 'quote'))->toBeEmpty();
    expect(collect($this->qGet('u03', 'search?q='.$quote['code'])->json('data'))->where('type', 'quote')->pluck('code')->all())->toBe([$quote['code']]);
    expect(collect($this->qGet('manager', 'quotes')->json('data'))->pluck('code'))->toContain($quote['code']);
    $this->qGet('u04', 'quotes/kpis')->assertOk()->assertJsonPath('data.open', 0);
});

it('T-Q04: Finanzas → 403 en enviar y aprobar (pero ve márgenes); Operaciones → 403 en GET quotes', function () {
    $quote = $this->makeQuote();

    $this->sendQuote('finance', $quote['code'])->assertForbidden();
    $this->qPost('finance', "quotes/{$quote['code']}/approve", ['approved_by_name' => 'X', 'channel' => 'Mensaje de WhatsApp', 'ref' => 'x'],
        $this->quoteLock($quote['code']) + $this->idem())->assertForbidden();
    $detail = $this->qGet('finance', "quotes/{$quote['code']}")->assertOk()->json('data');
    expect($detail['version']['totals'])->toHaveKey('margin_pct')
        ->and(collect($detail['actions'])->firstWhere('key', 'send'))->toMatchArray(['enabled' => false, 'reason' => 'Requiere permiso quotes.send']);
    $this->qGet('ops', 'quotes')->assertForbidden();
    $this->qGet('accounting', 'quotes')->assertForbidden();
});

it('T-Q05: producto SUSPENDED o no cotizable → 422 PRD-BR-002; tarifa fuera de vigencia → 422 PRD-BR-005', function () {
    $suspended = $this->makeProduct([], Product::SUSPENDED);
    $this->qPost('u03', 'quotes', $this->quotePayload('u03', [], ['product_id' => $suspended->id, 'rate_id' => null, 'unit_cost' => 10, 'unit_price' => 20]), $this->idem())
        ->assertStatus(422)->assertJsonPath('code', 'PRD-BR-002');

    $this->admin()->table('catalog_products')->where('id', $this->product->id)->update(['is_quoteable' => false]);
    $this->qPost('u03', 'quotes', $this->quotePayload(), $this->idem())->assertStatus(422)->assertJsonPath('code', 'PRD-BR-002');
    $this->admin()->table('catalog_products')->where('id', $this->product->id)->update(['is_quoteable' => true]);

    $this->qPost('u03', 'quotes', $this->quotePayload('u03', [], ['service_date' => now()->addYears(2)->toDateString()]), $this->idem())
        ->assertStatus(422)->assertJsonPath('code', 'PRD-BR-005');
});

it('T-Q06: descuento 12 % por Ventas → 422 QTE-BR-08; por Manager → OK y auditado descuento_sobre_tope', function () {
    $this->qPost('u03', 'quotes', $this->quotePayload('u03', ['discount_pct' => 12]), $this->idem())
        ->assertStatus(422)->assertJsonPath('code', 'QTE-BR-08');

    $quote = $this->qPost('manager', 'quotes', $this->quotePayload('u03', ['discount_pct' => 12]), $this->idem())->assertCreated()->json('data');
    expect($quote['internal_approval']['state'])->toBe('requerida')
        ->and($quote['internal_approval']['over_cap'])->toBeTrue()
        ->and(collect($this->auditRows('Cotización', $quote['code']))->pluck('action')->all())->toContain('descuento_sobre_tope');
});

it('T-Q07: margen < 15 % → send 409 QTE-BR-12; Ventas solicita, el manager aprueba y el envío pasa', function () {
    $quote = $this->makeQuote('u03', [], ['rate_id' => null, 'unit_cost' => 350, 'unit_price' => 380]);
    expect($quote['internal_approval']['reasons'])->toBe(['margen 7.9% < 15%']);
    expect(collect($quote['actions'])->firstWhere('key', 'send'))->toMatchArray(['enabled' => false, 'reason' => 'Bloqueado: requiere aprobación de gerencia (margen 7.9% < 15%)']);
    expect(collect($quote['actions'])->pluck('key'))->toContain('request_approval');

    $this->sendQuote('u03', $quote['code'])->assertStatus(409)->assertJsonPath('code', 'QTE-BR-12');
    $this->qPost('u03', "quotes/{$quote['code']}/internal-approval-requests", [])->assertStatus(422);
    $this->qPost('u03', "quotes/{$quote['code']}/internal-approval-requests", ['note' => 'Cliente recurrente'])->assertCreated()
        ->assertJsonPath('data.internal_approval.state', 'pendiente');
    $this->qPost('u03', "quotes/{$quote['code']}/internal-approval-requests", ['note' => 'otra vez'])->assertStatus(409)->assertJsonPath('code', 'QTE-BR-15');
    expect($this->admin()->table('notif_messages')->where('event', 'cotizacion.aprobacion_solicitada')->where('user_id', $this->users['manager']->id)->exists())->toBeTrue();
    $this->qGet('manager', 'quotes/kpis')->assertJsonPath('data.pending_internal', 1)->assertJsonPath('meta.pending_requests.0.quote', $quote['code']);

    $this->qPost('u03', "quotes/{$quote['code']}/versions/1/internal-approval", ['decision' => 'approve', 'note' => 'ok'], $this->quoteLock($quote['code']))->assertForbidden();
    $this->qPost('manager', "quotes/{$quote['code']}/versions/1/internal-approval", ['decision' => 'approve'], $this->quoteLock($quote['code']))->assertStatus(422);
    $this->qPost('manager', "quotes/{$quote['code']}/versions/1/internal-approval", ['decision' => 'approve', 'note' => 'Volumen anual'], $this->quoteLock($quote['code']))
        ->assertOk()->assertJsonPath('data.internal_approval.state', 'aprobada');
    expect($this->admin()->table('notif_messages')->where('event', 'cotizacion.aprobacion_aprobada')->where('user_id', $this->users['u03']->id)->exists())->toBeTrue();

    $sent = $this->sendQuote('u03', $quote['code'], ['Email', 'Portal'])->assertOk()->json('data');
    expect($sent['status'])->toBe('enviada')
        ->and($sent['version']['status'])->toBe('enviada')
        ->and($sent['version']['frozen_at'])->not->toBeNull()
        ->and($sent['token']['url'])->toContain('/c/')
        ->and($sent['sends'])->toHaveCount(2);
});

it('T-Q08: editar el borrador tras la aprobación interna la elimina y anula la solicitud', function () {
    $quote = $this->makeQuote('u03', ['discount_pct' => 8]);
    $this->qPost('u03', "quotes/{$quote['code']}/internal-approval-requests", ['note' => 'Promoción'])->assertCreated();
    $this->qPost('manager', "quotes/{$quote['code']}/versions/1/internal-approval", ['decision' => 'approve', 'note' => 'ok'], $this->quoteLock($quote['code']))->assertOk();
    $version = $this->admin()->table('quote_versions')->where('quote_id', $quote['id'])->first();
    expect($version->internal_approval_id)->not->toBeNull();

    $this->as('u03')->withHeaders(['If-Match' => (string) $version->lock_version])
        ->putJson("/api/v1/quotes/{$quote['code']}/versions/1", $this->quotePayload('u03', ['discount_pct' => 9]))
        ->assertOk()->assertJsonPath('data.internal_approval.state', 'requerida')->assertJsonPath('data.version.discount_pct', '9.00');

    expect($this->admin()->table('quote_versions')->where('id', $version->id)->value('internal_approval_id'))->toBeNull()
        ->and($this->admin()->table('quote_internal_approvals')->where('quote_version_id', $version->id)->pluck('status')->all())->toBe(['anulada'])
        ->and(collect($this->auditRows('Cotización', $quote['code']))->pluck('action')->all())->toContain('anular_solicitud_aprobacion', 'editar_version');
});

it('T-Q09: PUT sobre versión enviada → 409; el trigger impide modificar ítems y montos congelados', function () {
    $quote = $this->makeQuote();
    $this->sendQuote('u03', $quote['code'])->assertOk();
    $version = $this->admin()->table('quote_versions')->where('quote_id', $quote['id'])->first();

    $this->as('u03')->withHeaders(['If-Match' => (string) $version->lock_version])
        ->putJson("/api/v1/quotes/{$quote['code']}/versions/1", $this->quotePayload())->assertStatus(409)->assertJsonPath('code', 'QTE-BR-10');

    expect(fn () => $this->admin()->table('quote_items')->where('quote_version_id', $version->id)->update(['unit_price' => 1]))
        ->toThrow(QueryException::class, 'QTE-BR-19');
    expect(fn () => $this->admin()->table('quote_versions')->where('id', $version->id)->update(['grand_total' => 1]))
        ->toThrow(QueryException::class, 'QTE-BR-19');
    // El estado sí puede cambiar (reemplazada, aprobada…).
    $this->admin()->table('quote_versions')->where('id', $version->id)->update(['status' => 'aprobada']);
    $this->as('u03')->withHeaders(['If-Match' => (string) ($version->lock_version)])
        ->putJson("/api/v1/quotes/{$quote['code']}/versions/1", $this->quotePayload())->assertStatus(409);
});

it('T-Q10: vencida → aprobar 409 QTE-BR-16; nueva versión → borrador con validez +10 d; JOB-02 la marca vencida', function () {
    $quote = $this->makeQuote();
    $this->sendQuote('u03', $quote['code'])->assertOk();
    $this->admin()->table('quote_quotes')->where('id', $quote['id'])->update(['valid_until' => now()->subDays(2)->toDateString()]);

    $this->qGet('u03', "quotes/{$quote['code']}")->assertJsonPath('data.display_status', 'vencida')->assertJsonPath('data.status', 'enviada');
    $this->qPost('u03', "quotes/{$quote['code']}/approve", ['approved_by_name' => 'Ana', 'channel' => 'Mensaje de WhatsApp', 'ref' => 'chat'],
        $this->quoteLock($quote['code']) + $this->idem())->assertStatus(409)->assertJsonPath('code', 'QTE-BR-16');
    $this->sendQuote('u03', $quote['code'])->assertStatus(409)->assertJsonPath('code', 'QTE-BR-16');

    $this->artisan('quotes:expire')->assertSuccessful();
    expect($this->fresh('quote_quotes', $quote['id'])->status)->toBe('vencida')
        ->and(collect($this->auditRows('Cotización', $quote['code']))->firstWhere('action', 'vencer')->actor_type)->toBe('system');

    $v2 = $this->qPost('u03', "quotes/{$quote['code']}/versions", [], $this->quoteLock($quote['code']))->assertCreated()->json('data');
    expect($v2['status'])->toBe('borrador')
        ->and($v2['version_no'])->toBe(2)
        ->and($v2['valid_until'])->toBe(now('America/Lima')->addDays(10)->toDateString())
        ->and(collect($v2['versions'])->pluck('status', 'version_no')->all())->toBe([1 => 'reemplazada', 2 => 'borrador']);
});

it('T-Q11: rechazo sin motivo → 422; con motivo → rechazada y notifica al dueño', function () {
    $quote = $this->makeQuote();
    $this->sendQuote('u03', $quote['code'])->assertOk();

    $this->qPost('u03', "quotes/{$quote['code']}/reject", [], $this->quoteLock($quote['code']))->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->qPost('u03', "quotes/{$quote['code']}/reject", ['reason' => 'Eligió otra agencia'], $this->quoteLock($quote['code']))
        ->assertOk()->assertJsonPath('data.status', 'rechazada')->assertJsonPath('data.rejection.reason', 'Eligió otra agencia');
    $this->sendQuote('u03', $quote['code'])->assertStatus(409)->assertJsonPath('code', 'QTE-BR-23');
});

it('T-Q12: cambiar el precio del producto después no altera la versión enviada; la nueva versión lleva el snapshot vigente', function () {
    $quote = $this->makeQuote();
    $this->sendQuote('u03', $quote['code'])->assertOk();
    $this->inTenant(function () {
        $rate = $this->product->rates()->where('channel', 'Directo')->first();
        app(RateService::class)->changePrice($rate, ['cost' => 220, 'price' => 500, 'reason' => 'Ajuste de temporada'], $this->users['admin']);
    });

    $sent = $this->qGet('u03', "quotes/{$quote['code']}/versions/1")->assertOk()->json('data');
    expect($sent['items'][0]['unit_price'])->toBe('380.00')
        ->and($sent['totals']['total'])->toBe('896.80')
        ->and($sent['catalog_snapshot'][0]['rate']['price'])->toBe('380.00');
});

it('T-Q13: doble POST send con la misma Idempotency-Key → un solo envío', function () {
    $quote = $this->makeQuote();
    $headers = $this->quoteLock($quote['code']) + $this->idem('send-1');

    $this->qPost('u03', "quotes/{$quote['code']}/send", ['channels' => ['Email']], $headers)->assertOk();
    $this->qPost('u03', "quotes/{$quote['code']}/send", ['channels' => ['Email']], $headers)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect($this->admin()->table('quote_sends')->where('quote_id', $quote['id'])->count())->toBe(1)
        ->and($this->admin()->table('notif_messages')->where('event', 'cotizacion.enviada')->count())->toBe(1);
});

it('T-Q14: If-Match desactualizado → 409 STALE_VERSION; sin If-Match → 428', function () {
    $quote = $this->makeQuote();
    $this->sendQuote('u03', $quote['code'])->assertOk();

    $this->qPost('u03', "quotes/{$quote['code']}/reject", ['reason' => 'x'], ['If-Match' => '0'])->assertStatus(409)->assertJsonPath('error', 'STALE_VERSION');
    $this->qPost('u03', "quotes/{$quote['code']}/reject", ['reason' => 'x'])->assertStatus(428);
});

it('PROC-023/024: enlace del cliente → vista; QTE-BR-13 bloquea aprobar con aprobación interna pendiente; aprobación con IP y hash', function () {
    $quote = $this->makeQuote();
    $sent = $this->sendQuote('u03', $quote['code'])->assertOk()->json('data');
    $token = basename($sent['token']['url']);

    $this->flushHeaders();
    $public = $this->getJson("/api/v1/public/quotes/{$token}")->assertOk()->json('data');
    expect($public['status'])->toBe('vista')->and($public['totals']['total'])->toBe('896.80')->and($public['items'][0])->not->toHaveKey('unit_cost')
        ->and($public['can_respond'])->toBeTrue();
    expect($this->admin()->table('notif_messages')->where('event', 'cotizacion.vista')->where('user_id', $this->users['u03']->id)->exists())->toBeTrue();

    $this->postJson("/api/v1/public/quotes/{$token}/approve", ['approved_by_name' => 'Ana'], $this->idem())->assertStatus(422)->assertJsonValidationErrors('accept_conditions');
    $approved = $this->postJson("/api/v1/public/quotes/{$token}/approve", ['approved_by_name' => 'Ana Gutiérrez', 'accept_conditions' => true], $this->idem())->assertOk()->json('data');
    expect($approved['status'])->toBe('aprobada');
    $row = $this->fresh('quote_quotes', $quote['id']);
    expect($row->approval_channel)->toBe('Aceptación en portal (enlace con token)')
        ->and($row->approval_ip)->toBe('127.0.0.1')
        ->and($row->approval_hash)->toStartWith('sha256:')
        ->and($row->approval_recorded_by)->toBeNull();
    expect(collect($this->auditRows('Cotización', $quote['code']))->pluck('action')->all())->toContain('vista_portal', 'aprobar_portal');

    $this->getJson('/api/v1/public/quotes/token-invalido')->assertNotFound();
});

it('QTE-BR-13: el cliente no aprueba con la aprobación interna pendiente; el manager la registra en el mismo paso', function () {
    $quote = $this->makeQuote('u03', ['discount_pct' => 8]);
    $this->qPost('manager', "quotes/{$quote['code']}/versions/1/internal-approval", ['decision' => 'approve', 'note' => 'ok'], $this->quoteLock($quote['code']))->assertOk();
    $this->sendQuote('u03', $quote['code'])->assertOk();
    // Simula una versión enviada cuya aprobación quedó anulada (dato heredado).
    $this->admin()->table('quote_internal_approvals')->update(['status' => 'anulada']);
    $this->admin()->statement('ALTER TABLE quote_versions DISABLE TRIGGER quote_versions_frozen');
    $this->admin()->table('quote_versions')->update(['internal_approval_id' => null]);
    $this->admin()->statement('ALTER TABLE quote_versions ENABLE TRIGGER quote_versions_frozen');

    $evidence = ['approved_by_name' => 'Ana', 'channel' => 'Correo electrónico del cliente', 'ref' => 'RE: propuesta'];
    $this->qPost('u03', "quotes/{$quote['code']}/approve", $evidence, $this->quoteLock($quote['code']) + $this->idem())->assertStatus(409)->assertJsonPath('code', 'QTE-BR-13');
    $this->qPost('manager', "quotes/{$quote['code']}/approve", $evidence + ['internal_approval' => ['note' => 'Autorizo en la llamada']], $this->quoteLock($quote['code']) + $this->idem())
        ->assertOk()->assertJsonPath('data.status', 'aprobada')->assertJsonPath('data.internal_approval.state', 'aprobada');
});

it('negociación pasa la oportunidad a negociación y puede crear v2; comparar versiones muestra el diff', function () {
    $opp = $this->makeOpportunity('u03', $this->clients['u03']);
    $quote = $this->makeQuote('u03', ['opportunity_id' => $opp->id]);
    $this->sendQuote('u03', $quote['code'])->assertOk();

    $this->qPost('u03', "quotes/{$quote['code']}/negotiations", ['channel' => 'Llamada'])->assertStatus(422);
    $negotiated = $this->qPost('u03', "quotes/{$quote['code']}/negotiations", ['channel' => 'Llamada', 'note' => 'Pide 3 pax', 'create_version' => true])
        ->assertCreated()->json('data');
    expect($negotiated['version_no'])->toBe(2)->and($negotiated['negotiations'][0]['created_version_no'])->toBe(2);
    expect($this->fresh('crm_opportunities', $opp->id)->stage)->toBe('negociacion');

    $v2 = $this->admin()->table('quote_versions')->where('quote_id', $quote['id'])->where('version_no', 2)->first();
    $this->as('u03')->withHeaders(['If-Match' => (string) $v2->lock_version])
        ->putJson("/api/v1/quotes/{$quote['code']}/versions/2", $this->quotePayload('u03', ['pax_adt' => 3, 'opportunity_id' => $opp->id], ['pax' => 3]))->assertOk();
    $diff = $this->qGet('u03', "quotes/{$quote['code']}/versions/compare?a=1&b=2")->assertOk()->json('data');
    expect($diff['lines'][0]['change'])->toBe('modificado')
        ->and($diff['lines'][0]['changed_fields'])->toContain('pax', 'sale_total')
        ->and(collect($diff['summary'])->firstWhere('field', 'grand_total'))->toMatchArray(['a' => '896.80', 'b' => '1345.20', 'changed' => true]);
});

it('portal del cliente: ve solo sus cotizaciones enviadas, sin costos; aprueba aceptando condiciones', function () {
    $draft = $this->makeQuote();
    $quote = $this->makeQuote();
    $this->sendQuote('u03', $quote['code'])->assertOk();
    $customer = $this->makeUser($this->tenant, 'customer', ['client_id' => $this->clients['u03']->id]);
    $token = $this->tokenFor($customer);

    expect(collect($this->api($token)->getJson('/api/v1/portal/quotes')->assertOk()->json('data'))->pluck('code')->all())->toBe([$quote['code']]);
    $this->api($token)->getJson("/api/v1/portal/quotes/{$draft['code']}")->assertNotFound();
    $this->api($token)->getJson('/api/v1/quotes')->assertForbidden();
    $this->api($token)->getJson("/api/v1/portal/quotes/{$quote['code']}")->assertOk()->assertJsonPath('data.status', 'vista')->assertJsonMissingPath('data.version');
    $this->api($token)->postJson("/api/v1/portal/quotes/{$quote['code']}/approve", ['accept_conditions' => true], $this->idem())
        ->assertOk()->assertJsonPath('data.status', 'aprobada')->assertJsonPath('data.approval.by_name', $customer->name);
});

it('QTE-BR-25: solo quien tiene quotes.reassign reasigna; duplicar crea v1 del usuario actual', function () {
    $quote = $this->makeQuote();
    $this->qPost('u03', "quotes/{$quote['code']}/reassign", ['owner_id' => $this->users['u04']->id], $this->quoteLock($quote['code']))->assertForbidden();
    $this->qPost('manager', "quotes/{$quote['code']}/reassign", ['owner_id' => $this->users['u03']->id], $this->quoteLock($quote['code']))->assertStatus(422);
    $this->qPost('manager', "quotes/{$quote['code']}/reassign", ['owner_id' => $this->users['u04']->id, 'note' => 'Vacaciones'], $this->quoteLock($quote['code']))
        ->assertOk()->assertJsonPath('data.owner.id', $this->users['u04']->id);
    expect($this->admin()->table('notif_messages')->where('event', 'cotizacion.reasignada')->where('user_id', $this->users['u04']->id)->exists())->toBeTrue();

    $copy = $this->qPost('manager', "quotes/{$quote['code']}/duplicate", [], $this->idem())->assertCreated()->json('data');
    expect($copy['code'])->not->toBe($quote['code'])->and($copy['owner']['id'])->toBe($this->users['manager']->id)
        ->and($copy['duplicated_from'])->toBe($quote['code'])->and($copy['version']['totals']['total'])->toBe('896.80');
});

it('integraciones: 360 del cliente, uso del producto, oportunidad ganada exige cotización aprobada y exportación CSV', function () {
    $opp = $this->makeOpportunity('u03', $this->clients['u03']);
    $quote = $this->makeQuote('u03', ['opportunity_id' => $opp->id]);

    $tab = $this->as('u03')->getJson("/api/v1/crm/clients/{$this->clients['u03']->code}/quotes")->assertOk()->json('data');
    expect($tab[0]['code'])->toBe($quote['code']);
    expect($this->as('u03')->getJson("/api/v1/crm/opportunities/{$opp->code}")->json('data.quotes.0.code'))->toBe($quote['code']);
    $usage = $this->as('manager')->getJson("/api/v1/catalog/products/{$this->product->ref}/usage")->assertOk()->json('data');
    expect(collect($usage['quotes'])->pluck('code')->all())->toBe([$quote['code']]);

    $csv = $this->as('u03')->postJson('/api/v1/exports', ['resource' => 'quotes.quotes'])->assertOk();
    expect($csv->getContent())->toContain($quote['code'])->toContain('Margen %');

    $linked = fn () => $this->inTenant(fn () => app(LinkedData::class)->hasApprovedQuote($opp->id));
    expect($linked())->toBeFalse();
    $this->sendQuote('u03', $quote['code'])->assertOk();
    $this->qPost('u03', "quotes/{$quote['code']}/approve", ['approved_by_name' => 'Ana', 'channel' => 'Firma de conformidad', 'ref' => 'Acta 12'],
        $this->quoteLock($quote['code']) + $this->idem())->assertOk();
    expect($linked())->toBeTrue();
});
