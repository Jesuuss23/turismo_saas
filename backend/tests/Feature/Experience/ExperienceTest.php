<?php

use App\Domain\Bookings\BookingEngine;
use App\Domain\Bookings\QuoteSelection;
use App\Domain\Bookings\RuleCatalog;
use App\Models\Bookings\Reservation;
use App\Models\Experience\PaxMessage;
use App\Services\Experience\PaxMessageService;
use App\Services\Experience\WidgetService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->bookingWorld();
});

function x17Rule(object $test, string $key, bool $enabled): void
{
    $test->as('admin')->patchJson("/api/v1/admin/booking-rules/{$key}", ['enabled' => $enabled])->assertOk();
}

function x17Quote(object $test, array $sel): array
{
    return $test->inTenant(fn () => app(BookingEngine::class)->quote($test->circuit->fresh(), QuoteSelection::fromArray($sel))->toArray());
}

function x17Key(object $test): string
{
    return DB::connection('pgsql_admin')->table('channel_widget_config as c')->join('tenants as t', 't.id', '=', 'c.tenant_id')->where('t.slug', 'andes')->value('c.public_key');
}

/** Reserva confirmada del circuito con un cliente del país indicado. */
function x17Reservation(object $test, string $country = 'Perú', string $date = '2026-10-05'): Reservation
{
    $r = $test->soldOn($date, 2);
    $doc = $country === 'Perú' ? ['doc_type' => 'DNI', 'doc_number' => (string) random_int(10000000, 99999999)] : ['doc_type' => 'PASAPORTE', 'doc_number' => 'X'.random_int(1000000, 9999999)];
    $client = $test->makeClient('u03', ['name' => $country === 'Perú' ? 'Rosa Quispe' : 'John Carter', 'country' => $country, 'phone' => '+51 987 654 321'] + $doc);

    return $test->inTenant(function () use ($r, $client) {
        $r->forceFill(['client_id' => $client->id])->save();

        return $r->fresh();
    });
}

// --- §10 caso 1: aislamiento ---

it('§10.1 aísla reglas, plantillas, mensajes, monedas, reseñas y dominios por tenant; la clave pública de A no cotiza productos de B', function () {
    $this->as('admin')->postJson('/api/v1/channels/widget/domains', ['domain' => 'www.andes-tours.test', 'cms' => 'WordPress'])->assertCreated();
    $template = $this->as('admin')->getJson('/api/v1/notifications/pax-templates')->assertOk()->assertJsonCount(10, 'data')->json('data.0.id');
    $b = $this->makeTenant('beta');
    $tokenB = $this->tokenFor($this->makeUser($b, 'tenant_admin', ['code' => 'B01', 'name' => 'Beta']));
    $this->api($tokenB)->getJson('/api/v1/admin/booking-rules')->assertOk()->assertJsonCount(32, 'data');
    $this->api($tokenB)->getJson('/api/v1/notifications/pax-templates')->assertOk()->assertJsonCount(10, 'data');
    $this->api($tokenB)->postJson("/api/v1/notifications/pax-templates/{$template}/preview", ['channel' => 'email'])->assertNotFound();
    $this->api($tokenB)->getJson('/api/v1/notifications/pax-messages')->assertOk()->assertJsonCount(0, 'data');
    $this->api($tokenB)->getJson('/api/v1/finance/currencies')->assertOk()->assertJsonCount(10, 'data.items');
    $this->api($tokenB)->getJson('/api/v1/channels/external-reviews')->assertOk()->assertJsonCount(0, 'data.items');
    $this->api($tokenB)->getJson('/api/v1/channels/widget/domains')->assertOk()->assertJsonCount(0, 'data');
    // Producto de B: la clave de A no lo encuentra (RLS por el tenant de la clave).
    $productB = DB::connection('pgsql_admin')->table('catalog_products')->where('tenant_id', $b->id)->value('ref');
    expect($productB)->toBeNull();
    $this->pub('GET', "widget/products/{$this->circuit->ref}", [], ['X-Qhapaq-Key' => x17Key($this)])->assertOk()->assertJsonPath('data.ref', $this->circuit->ref);
    $keyB = DB::connection('pgsql_admin')->table('channel_widget_config')->where('tenant_id', $b->id)->value('public_key');
    $this->pub('GET', "widget/products/{$this->circuit->ref}", [], ['X-Qhapaq-Key' => $keyB])->assertNotFound();
    $this->pub('POST', 'widget-handoffs', ['product' => $this->circuit->ref, 'date' => '2026-10-05', 'pax' => ['adt' => 2]], ['X-Qhapaq-Key' => $keyB] + $this->idem())
        ->assertStatus(422)->assertJsonPath('error', 'VALIDATION_ERROR');
    $this->pub('GET', 'widget/config', [], ['X-Qhapaq-Key' => 'pk_live_inexistente'])->assertNotFound();
});

// --- caso 2: RBAC ---

it('§10.2 RBAC: Operaciones 403 en reglas y 201 en envío manual; Finanzas edita el TC pero no activa; Manager 403 al regenerar la clave', function () {
    $r = x17Reservation($this);
    $this->as('ops')->patchJson('/api/v1/admin/booking-rules/R-HOLD', ['params' => ['minutes' => 20]])->assertForbidden();
    $this->as('ops')->withHeaders($this->idem())->postJson("/api/v1/reservations/{$r->code}/pax-messages", ['channel' => 'email', 'body' => 'Hola {{pasajero}}, su recojo es a las 7:00.'])
        ->assertCreated()->assertJsonPath('data.origin', 'manual')->assertJsonPath('data.status', 'enviado')->assertJsonPath('data.body', 'Hola Rosa Quispe, su recojo es a las 7:00.');
    $this->as('ops')->postJson('/api/v1/notifications/pax-templates', ['name' => 'X'])->assertForbidden();
    $this->as('ops')->getJson('/api/v1/notifications/pax-templates')->assertOk()->assertJsonPath('meta.can_edit', false);
    $this->as('u03')->getJson('/api/v1/notifications/pax-messages')->assertForbidden();

    $this->as('finance')->patchJson('/api/v1/finance/currencies/USD', ['rate_pen' => 3.77])->assertOk()->assertJsonPath('data.currency.rate_pen', '3.770000');
    $this->as('finance')->patchJson('/api/v1/finance/currencies/EUR', ['active' => false])->assertForbidden();
    $this->as('finance')->patchJson('/api/v1/finance/fx-settings', ['fx_alert_pct' => 5])->assertForbidden();
    $this->as('u03')->getJson('/api/v1/finance/currencies')->assertForbidden();

    $this->as('manager')->withHeaders($this->idem())->postJson('/api/v1/channels/widget/rotate-key', ['reason' => 'Clave expuesta en un tema'])->assertForbidden();
    $this->as('manager')->getJson('/api/v1/channels/widget')->assertOk()->assertJsonPath('meta.can_rotate', false);
    $this->as('manager')->getJson('/api/v1/admin/booking-rules')->assertForbidden();
    $this->as('u03')->getJson('/api/v1/channels/external-reviews')->assertOk();
    $this->as('u03')->withHeaders($this->idem())->postJson('/api/v1/integrations/review-sources/google', ['account_ref' => 'x', 'secret' => 'abcdefgh1234', 'contract_ack' => true])->assertForbidden();
    $this->as('ops')->getJson('/api/v1/channels/widget')->assertForbidden();

    $old = x17Key($this);
    $this->as('admin')->withHeaders($this->idem())->postJson('/api/v1/channels/widget/rotate-key', ['reason' => 'Clave expuesta en un tema'])->assertOk()
        ->assertJsonPath('data.public_key_prev', $old);
    // WG-02: la clave anterior sigue valiendo 24 h.
    $this->pub('GET', 'widget/config', [], ['X-Qhapaq-Key' => $old])->assertOk();
    $this->travel(25)->hours();
    $this->pub('GET', 'widget/config', [], ['X-Qhapaq-Key' => $old])->assertNotFound();
});

// --- caso 3: CR-01 ---

it('§10.3 CR-01 valida rangos cruzados de pasajeros y el adelanto 1–99 %', function () {
    $this->as('admin')->patchJson('/api/v1/admin/booking-rules/R-CHILD-PER-ADULT', ['params' => ['childMinAge' => 12, 'childMaxAge' => 10]])->assertStatus(422)->assertJsonPath('code', 'CR-01');
    // Infante hasta 4 con niño desde 3 (por defecto) → 422; niño desde 2 con infante hasta 2 → 422.
    $this->as('admin')->patchJson('/api/v1/admin/booking-rules/R-INFANT-FREE', ['params' => ['infantMaxAge' => 4]])->assertStatus(422)->assertJsonPath('code', 'CR-01');
    $this->as('admin')->patchJson('/api/v1/admin/booking-rules/R-CHILD-PER-ADULT', ['params' => ['childMinAge' => 2]])->assertStatus(422)->assertJsonPath('code', 'CR-01');
    $this->as('admin')->patchJson('/api/v1/admin/booking-rules/R-CHILD-PER-ADULT', ['params' => ['childMaxAge' => 18]])->assertStatus(422)->assertJsonPath('code', 'CR-01');
    $this->as('admin')->patchJson('/api/v1/admin/booking-rules/R-DEPOSIT', ['params' => ['pct' => 100]])->assertStatus(422);
    $this->as('admin')->patchJson('/api/v1/admin/booking-rules/R-DEPOSIT', ['params' => ['pct' => 40]])->assertOk()->assertJsonPath('data.params.pct', 40);
    // If-Match obsoleto → 409; auditoría con antes/después.
    $this->as('admin')->withHeaders(['If-Match' => '0'])->patchJson('/api/v1/admin/booking-rules/R-DEPOSIT', ['params' => ['pct' => 35]])->assertStatus(409);
    expect(collect($this->auditRows('Regla de reserva', 'R-DEPOSIT'))->pluck('action')->all())->toBe(['editar_parametros']);
});

// --- caso 4: CR-02 ---

it('§10.4 CR-02: sin product_override la excepción es 422 y una excepción no enciende una regla apagada del tenant', function () {
    $ref = $this->circuit->ref;
    $this->as('admin')->putJson("/api/v1/catalog/products/{$ref}/rule-overrides/R-MULTICURRENCY", ['enabled' => false])->assertStatus(422)->assertJsonPath('code', 'CAT-RL-01');
    x17Rule($this, 'R-GROUP-DISC', false);
    $this->as('admin')->putJson("/api/v1/catalog/products/{$ref}/rule-overrides/R-GROUP-DISC", ['enabled' => true])->assertOk();
    expect($this->inTenant(fn () => app(BookingEngine::class)->rules->on('R-GROUP-DISC', $this->circuit)))->toBeFalse();
    // El drawer de excepciones lista los productos que la sobrescriben.
    $this->as('admin')->putJson("/api/v1/catalog/products/{$ref}/rule-overrides/R-ADDONS", ['enabled' => false])->assertOk();
    $this->as('admin')->getJson('/api/v1/admin/booking-rules/R-ADDONS/overrides')->assertOk()->assertJsonPath('data.items.0.ref', $ref);
    $this->as('admin')->getJson('/api/v1/admin/booking-rules')->assertOk()->assertJsonPath('data.'.array_search('R-ADDONS', array_keys(RuleCatalog::RULES)).'.overrides', 1);
});

// --- caso 5: regla de oro ---

it('§10.5 regla de oro: sin R-LODGING se ignora el alojamiento, sin R-PREVIEW-REMOVE removed[] no cambia el total y sin R-MULTICURRENCY otra moneda es 422', function () {
    $base = ['date' => '2026-10-05', 'pax' => ['adt' => 2], 'currency' => 'USD'];
    $withLodging = x17Quote($this, $base + ['lodging' => $this->cheapestLodging()]);
    $plain = x17Quote($this, $base);
    expect((float) $withLodging['total'])->toBeGreaterThan((float) $plain['total']);
    x17Rule($this, 'R-LODGING', false);
    expect(x17Quote($this, $base + ['lodging' => $this->cheapestLodging()])['total'])->toBe($plain['total']);

    $addon = $this->inTenant(fn () => $this->circuit->fresh()->addons()->first()->id);
    $q = x17Quote($this, $base + ['addons' => [['id' => $addon, 'qty' => 1]]]);
    $key = collect($q['lines'])->firstWhere('kind', 'addon')['key'];
    expect(x17Quote($this, $base + ['addons' => [['id' => $addon, 'qty' => 1]], 'removed' => [$key]])['total'])->toBe($plain['total']);
    x17Rule($this, 'R-PREVIEW-REMOVE', false);
    expect(x17Quote($this, $base + ['addons' => [['id' => $addon, 'qty' => 1]], 'removed' => [$key]])['total'])->toBe($q['total']);

    // R-MULTICURRENCY encendida: EUR activa se cotiza con TC × (1 − margen); apagada, solo PEN o la moneda de la tarifa.
    $this->as('admin')->patchJson('/api/v1/finance/fx-settings', ['fx_margin_pct' => 2])->assertOk();
    $eur = x17Quote($this, ['date' => '2026-10-05', 'pax' => ['adt' => 1], 'currency' => 'EUR']);
    expect($eur['currency'])->toBe('EUR')->and($eur['total'])->toBe(number_format(round(1490 * 3.75 / (4.05 * 0.98), 2), 2, '.', ''));
    $this->pub('POST', 'quote', ['product_id' => $this->circuit->id, 'date' => '2026-10-05', 'pax' => ['adt' => 1], 'currency' => 'CLP'])->assertStatus(422)->assertJsonPath('code', 'CURRENCY_NOT_ALLOWED');
    x17Rule($this, 'R-MULTICURRENCY', false);
    $this->pub('POST', 'quote', ['product_id' => $this->circuit->id, 'date' => '2026-10-05', 'pax' => ['adt' => 1], 'currency' => 'EUR'])->assertStatus(422)->assertJsonPath('code', 'CURRENCY_NOT_ALLOWED');
    $this->pub('POST', 'quote', ['product_id' => $this->circuit->id, 'date' => '2026-10-05', 'pax' => ['adt' => 1], 'currency' => 'PEN'])->assertOk()->assertJsonPath('data.currency', 'PEN');
});

// --- caso 6: MSG-02 ---

it('§10.6 MSG-02: recalcular dos veces no duplica; con R-PAX-MESSAGES apagada no quedan programados; cliente extranjero recibe la plantilla EN', function () {
    $r = x17Reservation($this);
    $first = $this->as('admin')->postJson('/api/v1/notifications/pax-messages/reschedule')->assertOk()->json('data');
    expect($first['scheduled'])->toBeGreaterThan(0);
    $count = $this->inTenant(fn () => PaxMessage::where('reservation_id', $r->id)->where('status', 'programado')->count());
    $this->as('ops')->postJson('/api/v1/notifications/pax-messages/reschedule')->assertOk()->assertJsonPath('data.scheduled', 0);
    expect($this->inTenant(fn () => PaxMessage::where('reservation_id', $r->id)->where('status', 'programado')->count()))->toBe($count);
    // Recordatorio 48 h antes del viaje a las 08:00 de Lima.
    $pmt004 = $this->inTenant(fn () => PaxMessage::where('reservation_id', $r->id)->whereHas('template', fn ($t) => $t->where('code', 'PMT-004'))->where('channel', 'email')->first());
    expect($pmt004->scheduled_at->setTimezone('America/Lima')->format('Y-m-d H:i'))->toBe('2026-10-03 08:00')->and($pmt004->body)->toContain('Rosa Quispe');

    x17Rule($this, 'R-PAX-MESSAGES', false);
    expect($this->inTenant(fn () => PaxMessage::where('origin', 'auto')->where('status', 'programado')->count()))->toBe(0);
    $this->as('admin')->getJson('/api/v1/notifications/pax-templates')->assertOk()->assertJsonPath('meta.messages_on', false);
    x17Rule($this, 'R-PAX-MESSAGES', true);

    $foreign = x17Reservation($this, 'Estados Unidos', '2026-10-10');
    $this->inTenant(fn () => app(PaxMessageService::class)->schedule($foreign->id, true));
    $confirmation = $this->inTenant(fn () => PaxMessage::where('reservation_id', $foreign->id)->whereHas('template', fn ($t) => $t->where('group_code', 'PMT-001'))->get());
    expect($confirmation->pluck('lang')->unique()->all())->toBe(['EN'])
        ->and($confirmation->first()->subject)->toBe("Your booking {$foreign->code} is confirmed");
    // Despacho: los vencidos pasan a enviado; el acuse del proveedor (firmado) marca entregado y leído.
    $this->inTenant(fn () => app(PaxMessageService::class)->dispatchDue());
    $sent = $this->inTenant(fn () => PaxMessage::where('reservation_id', $foreign->id)->where('channel', 'whatsapp')->whereHas('template', fn ($t) => $t->where('code', 'PMT-010'))->firstOrFail());
    expect($sent->status)->toBe('enviado');
    $body = json_encode(['events' => [['message_id' => $sent->provider_message_id, 'status' => 'read']]]);
    $server = fn (string $sig) => ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_TENANT_SLUG' => $this->tenant->slug, 'HTTP_X_PROVIDER_SIGNATURE' => $sig];
    $this->call('POST', '/api/v1/webhooks/providers/whatsapp', [], [], [], $server('sha256=bad'), $body)->assertStatus(401);
    $this->call('POST', '/api/v1/webhooks/providers/whatsapp', [], [], [], $server('sha256='.hash_hmac('sha256', $body, 'sandbox-pax-provider-secret')), $body)
        ->assertOk()->assertJsonPath('data.0.status', 'leido');
});

// --- caso 7: MSG-01 ---

it('§10.7 MSG-01: variable desconocida y WhatsApp sobre 1 024 caracteres son 422; asunto obligatorio fuera de WhatsApp', function () {
    $ok = ['name' => 'Aviso de clima', 'event' => 'viaje', 'offset_hours' => -24, 'channels' => ['email'], 'subject' => 'Clima en {{producto}}', 'body' => 'Hola {{pasajero}}, lleve abrigo.', 'lang' => 'ES'];
    $this->as('admin')->withHeaders($this->idem())->postJson('/api/v1/notifications/pax-templates', ['body' => 'Hola {{nombre}}'] + $ok)->assertStatus(422)->assertJsonPath('code', 'MSG-01');
    $this->as('admin')->withHeaders($this->idem())->postJson('/api/v1/notifications/pax-templates', ['channels' => ['whatsapp'], 'body' => str_repeat('a', 1025)] + $ok)->assertStatus(422)->assertJsonPath('code', 'MSG-01');
    $this->as('admin')->withHeaders($this->idem())->postJson('/api/v1/notifications/pax-templates', ['subject' => ''] + $ok)->assertStatus(422)->assertJsonPath('code', 'MSG-01');
    $created = $this->as('admin')->withHeaders($this->idem())->postJson('/api/v1/notifications/pax-templates', $ok)->assertCreated()->assertJsonPath('data.code', 'PMT-011')->json('data');
    $this->as('admin')->withHeaders(['If-Match' => (string) $created['lock_version']])->patchJson("/api/v1/notifications/pax-templates/{$created['id']}", ['active' => false])
        ->assertOk()->assertJsonPath('data.active', false);
    $this->as('admin')->withHeaders($this->idem())->postJson("/api/v1/notifications/pax-templates/{$created['id']}/duplicate", ['lang' => 'EN'])->assertCreated()
        ->assertJsonPath('data.group_code', 'PMT-011')->assertJsonPath('data.lang', 'EN');
    $this->as('admin')->deleteJson("/api/v1/notifications/pax-templates/{$created['id']}")->assertNoContent();
    // Plantilla con historial no se elimina.
    $this->as('admin')->withHeaders($this->idem())->postJson('/api/v1/notifications/pax-templates/PMT-004/test', ['channel' => 'email'])->assertOk()->assertJsonPath('data.origin', 'prueba');
    $this->as('admin')->deleteJson('/api/v1/notifications/pax-templates/PMT-004')->assertStatus(422);
});

// --- caso 8: FX-01/02 ---

it('§10.8 FX: TC ≤ 0 es 422, desactivar una moneda de cobro es 422 y la reserva conserva su TC; variación alta pide confirmación', function () {
    $this->as('admin')->patchJson('/api/v1/finance/currencies/USD', ['rate_pen' => 0])->assertStatus(422)->assertJsonPath('code', 'FX-01');
    $this->as('admin')->patchJson('/api/v1/finance/currencies/USD', ['active' => false])->assertStatus(422)->assertJsonPath('code', 'FX-02');
    $this->as('admin')->patchJson('/api/v1/finance/currencies/PEN', ['is_charge' => false])->assertStatus(422)->assertJsonPath('code', 'FX-02');
    $code = $this->bookOnline(['date' => '2026-10-05', 'pax' => ['adt' => 1], 'currency' => 'USD'])->assertCreated()->json('data.reservation');
    $reservation = $this->inTenant(fn () => Reservation::where('code', $code)->firstOrFail());
    expect((string) $reservation->fx_rate)->toBe('3.750000')->and($reservation->currency_display)->toBe('USD');
    $this->as('admin')->patchJson('/api/v1/finance/currencies/USD', ['rate_pen' => 3.95])->assertStatus(422)->assertJsonPath('requires_confirmation', true);
    $this->as('admin')->patchJson('/api/v1/finance/currencies/USD', ['rate_pen' => 3.95, 'confirm' => true])->assertOk()->assertJsonCount(1, 'data.warnings');
    expect((string) $this->inTenant(fn () => $reservation->fresh()->fx_rate))->toBe('3.750000')
        ->and($this->inTenant(fn () => $this->tenant->fresh()->fxToPen('USD')))->toBe(3.95);
    $this->as('admin')->getJson('/api/v1/finance/fx-rates?currency=USD&days=30')->assertOk()->assertJsonPath('data.0.rate', '3.950000')->assertJsonPath('data.0.alert', true);
    // Proveedor demo: actualiza las monedas activas (±0,6 %) y registra un valor por día.
    $refresh = $this->as('finance')->postJson('/api/v1/finance/currencies/refresh')->assertOk()->json('data');
    expect(count($refresh['updated']))->toBe(4);
    expect(DB::connection('pgsql_admin')->table('fin_fx_rates')->where('tenant_id', $this->tenant->id)->where('currency', 'USD')->count())->toBe(1);
    $this->as('admin')->getJson('/api/v1/finance/currencies/convert?amount=100&from=PEN&to=EUR')->assertOk()->assertJsonPath('data.from', 'PEN');
});

// --- caso 9: RV-03 ---

it('§10.9 RV-03: review-sources nunca devuelve el secreto; la respuesta exige 20 caracteres y la derivación crea una conversación', function () {
    $secret = 'sk_google_demo_9f8e7d6c';
    $this->as('admin')->withHeaders($this->idem())->postJson('/api/v1/integrations/review-sources/google', ['account_ref' => 'Andes Tours Cusco', 'secret' => $secret])
        ->assertStatus(422)->assertJsonPath('code', 'RV-01');
    $res = $this->as('admin')->withHeaders($this->idem())->postJson('/api/v1/integrations/review-sources/google', ['account_ref' => 'Andes Tours Cusco', 'secret' => $secret, 'contract_ack' => true])
        ->assertOk()->assertJsonPath('data.secret_hint', '••••7d6c')->assertJsonPath('data.status', 'conectado');
    $list = $this->as('admin')->getJson('/api/v1/integrations/review-sources')->assertOk();
    expect($res->getContent())->not->toContain($secret)->and($list->getContent())->not->toContain($secret)->and($list->getContent())->not->toContain('secret_encrypted');
    $stored = DB::connection('pgsql_admin')->table('integration_review_sources')->where('tenant_id', $this->tenant->id)->where('provider', 'google')->value('secret_encrypted');
    expect($stored)->not->toBe($secret)->not->toBeNull();

    $reviews = $this->as('u03')->getJson('/api/v1/channels/external-reviews')->assertOk()->assertJsonPath('data.kpis.is_example', true)->json('data.items');
    $low = collect($reviews)->firstWhere(fn ($r) => $r['rating'] <= 3);
    $this->as('u03')->withHeaders($this->idem())->postJson("/api/v1/channels/external-reviews/{$low['id']}/reply", ['reply' => 'Gracias.'])->assertStatus(422)->assertJsonPath('code', 'RV-02');
    $this->as('u03')->withHeaders($this->idem())->postJson("/api/v1/channels/external-reviews/{$low['id']}/reply", ['reply' => 'Lamentamos lo ocurrido, le contactaremos hoy mismo.'])
        ->assertOk()->assertJsonPath('data.response_status', 'pendiente_publicar');
    $this->as('u03')->withHeaders($this->idem())->postJson("/api/v1/channels/external-reviews/{$low['id']}/escalate")->assertOk()->assertJsonPath('data.conversation_id', fn ($id) => $id !== null);
    $high = collect($reviews)->firstWhere(fn ($r) => $r['rating'] >= 4);
    $this->as('u03')->withHeaders($this->idem())->postJson("/api/v1/channels/external-reviews/{$high['id']}/escalate")->assertStatus(422);
    $this->as('admin')->deleteJson('/api/v1/integrations/review-sources/google')->assertOk()->assertJsonPath('data.secret_hint', null);
    expect(DB::connection('pgsql_admin')->table('integration_review_sources')->where('tenant_id', $this->tenant->id)->where('provider', 'google')->value('secret_encrypted'))->toBeNull();
});

// --- caso 10: WG-01 e idempotencia del traspaso ---

it('§10.10 WG-01: Origin no verificado sin CORS; verificado con CORS; widget-handoffs es idempotente y la reserva lo convierte', function () {
    $key = ['X-Qhapaq-Key' => x17Key($this)];
    $domain = $this->as('admin')->postJson('/api/v1/channels/widget/domains', ['domain' => 'https://www.andes-tours.test/', 'cms' => 'WordPress'])->assertCreated()->json('data.0');
    expect($domain['domain'])->toBe('www.andes-tours.test')->and($domain['status'])->toBe('pendiente');
    $this->as('admin')->postJson('/api/v1/channels/widget/domains', ['domain' => 'no es un dominio'])->assertStatus(422);
    $this->pub('GET', 'widget/config', [], $key + ['Origin' => 'https://www.andes-tours.test'])->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
    $this->as('admin')->postJson("/api/v1/channels/widget/domains/{$domain['id']}/verify")->assertOk()->assertJsonPath('data.verified', true);
    $this->pub('GET', 'widget/config', [], $key + ['Origin' => 'https://evil.example.com'])->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
    $this->pub('GET', 'widget/config', [], $key + ['Origin' => 'https://www.andes-tours.test'])->assertOk()->assertHeader('Access-Control-Allow-Origin', 'https://www.andes-tours.test');
    $preflight = ['HTTP_ORIGIN' => 'https://www.andes-tours.test', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST'];
    $this->call('OPTIONS', '/api/v1/public/widget-handoffs', [], [], [], $preflight)->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://www.andes-tours.test');
    $this->call('OPTIONS', '/api/v1/public/widget-handoffs', [], [], [], ['HTTP_ORIGIN' => 'https://evil.example.com'] + $preflight)->assertHeaderMissing('Access-Control-Allow-Origin');

    $sel = ['product' => $this->circuit->ref, 'date' => '2026-10-05', 'pax' => ['adt' => 2, 'chd' => 1, 'inf' => 1], 'ages' => [40, 38, 8, 1], 'lodging' => $this->cheapestLodging(2),
        'payment_mode' => 'adelanto', 'currency' => 'EUR', 'lang' => 'es'];
    $idem = ['Idempotency-Key' => 'who-test-1'];
    $first = $this->pub('POST', 'widget-handoffs', $sel, $key + $idem + ['Origin' => 'https://www.andes-tours.test'])->assertCreated()->json('data');
    expect($first['code'])->toBe('WHO-00001')->and($first['currency'])->toBe('EUR')->and($first['checkout_url'])->toContain('/sitio/reserva/WHO-00001');
    $this->pub('POST', 'widget-handoffs', $sel, $key + $idem)->assertJsonPath('data.code', 'WHO-00001');
    expect(DB::connection('pgsql_admin')->table('channel_widget_handoffs')->where('tenant_id', $this->tenant->id)->count())->toBe(1);
    $this->pub('POST', 'widget-handoffs', ['date' => '2026-10-08'] + $sel, $key + $this->idem())->assertStatus(422)->assertJsonPath('code', 'DAY_NOT_AVAILABLE');

    // Checkout: recotiza en una moneda de cobro (EUR es solo de visualización) y la reserva conserva currency_display = EUR.
    $checkout = $this->pub('GET', 'widget-handoffs/WHO-00001')->assertOk()->assertJsonPath('data.status', 'en_carrito')->assertJsonPath('data.charge_currency', 'USD')->json('data');
    $this->pub('POST', 'bookings', ['quote_token' => $checkout['quote_token'], 'handoff' => 'WHO-00001', 'contact' => $this->contact(),
        'pickup' => ['point_id' => $this->inTenant(fn () => $this->circuit->pickupPoints()->first()->id)]], $this->idem())
        ->assertCreated()->assertJsonPath('data.currency_display', 'EUR')->assertJsonPath('data.handoff', 'WHO-00001');
    expect(DB::connection('pgsql_admin')->table('channel_widget_handoffs')->where('tenant_id', $this->tenant->id)->value('status'))->toBe('convertido');
    $this->travel(2)->days();
    $this->pub('POST', 'widget-handoffs', ['date' => '2026-10-12'] + $sel, $key + $this->idem())->assertCreated();
    $this->travel(2)->days();
    expect($this->inTenant(fn () => app(WidgetService::class)->expire()))->toBe(1);
});

// --- v2.2: plan, plantillas, restablecer y simulador ---

it('v2.2 PLN-R-01: fuera del plan la regla no aplica y no se activa (403); vista previa por plan, plantillas, restablecer y simulador', function () {
    DB::connection('pgsql_admin')->table('tenants')->where('id', $this->tenant->id)->update(['plan_id' => 'starter']);
    $this->tenant = $this->tenant->fresh();
    x17Rule($this, 'R-PAY-LATER', false);
    $this->as('admin')->patchJson('/api/v1/admin/booking-rules/R-PAY-LATER', ['enabled' => true])->assertForbidden()->assertJsonPath('code', 'RULE_NOT_IN_PLAN');
    $rules = collect($this->as('admin')->getJson('/api/v1/admin/booking-rules')->assertOk()->assertJsonPath('meta.plan', 'starter')->json('data'))->keyBy('key');
    expect($rules['R-LODGING']['state'])->toBe('off_plan')->and($rules['R-LODGING']['min_plan'])->toBe('professional')->and($rules['R-AVAIL-RANGES']['effective'])->toBeTrue()
        ->and($rules['R-LODGING-CAPACITY']['depends_ok'])->toBeFalse();
    expect($this->inTenant(fn () => app(BookingEngine::class)->rules->on('R-LODGING')))->toBeFalse();
    $preview = collect($this->as('admin')->getJson('/api/v1/admin/booking-rules?plan=professional')->assertOk()->assertJsonPath('meta.preview_plan', 'professional')->json('data'))->keyBy('key');
    expect($preview['R-LODGING']['effective'])->toBeTrue();
    $sim = $this->as('admin')->postJson('/api/v1/admin/booking-rules/simulate', ['product' => $this->circuit->ref, 'plan' => 'enterprise',
        'selection' => ['date' => '2026-10-05', 'pax' => ['adt' => 2], 'lodging' => $this->cheapestLodging()]])->assertOk()->json('data');
    expect($sim['day']['status'])->toBe('disponible')->and($sim['quote']['nights'])->toBe(9)->and(collect($sim['rules'])->firstWhere('key', 'R-LODGING')['state'])->toBe('on');
    DB::connection('pgsql_admin')->table('tenants')->where('id', $this->tenant->id)->update(['plan_id' => 'enterprise']);
    $this->tenant = $this->tenant->fresh();

    $templates = collect($this->as('admin')->getJson('/api/v1/admin/booking-rules/templates')->assertOk()->json('data'))->keyBy('code');
    expect($templates->keys()->all())->toBe(['aventura', 'b2b', 'minorista', 'receptivo'])->and(collect($templates['receptivo']['enable'])->pluck('key'))->toContain('R-REVIEW-INVITE');
    $this->as('admin')->postJson('/api/v1/admin/booking-rules/apply-template', ['template' => 'receptivo'])->assertOk();
    expect($this->inTenant(fn () => app(BookingEngine::class)->rules->on('R-REVIEW-INVITE')))->toBeTrue();
    $this->as('admin')->postJson('/api/v1/admin/booking-rules/reset', ['reason' => 'no'])->assertStatus(422);
    $this->as('admin')->postJson('/api/v1/admin/booking-rules/reset', ['reason' => 'Volver a la configuración de fábrica'])->assertOk();
    expect($this->inTenant(fn () => app(BookingEngine::class)->rules->on('R-REVIEW-INVITE')))->toBeFalse();
    $history = collect($this->as('admin')->getJson('/api/v1/admin/booking-rules/audit')->assertOk()->json('data'))->pluck('action');
    expect($history)->toContain('aplicar_plantilla', 'restablecer', 'activar');
    $this->as('admin')->postJson('/api/v1/admin/booking-rules', ['rule_key' => 'R-PETS', 'group_name' => 'Checkout', 'name' => 'Mascotas', 'description' => 'Mascotas'])->assertCreated();
    $this->as('admin')->deleteJson('/api/v1/admin/booking-rules/R-PETS')->assertNoContent();
    $this->as('admin')->deleteJson('/api/v1/admin/booking-rules/R-HOLD')->assertStatus(422)->assertJsonPath('code', 'CR-04');
});
