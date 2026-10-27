<?php

use App\Models\Crm\Client;
use App\Models\Iam\Sede;
use App\Models\Marketing\Subscriber;
use App\Models\Tenant;
use App\Services\Marketing\MessageRenderer;
use App\Services\SequenceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

const LIMA = 'America/Lima';

beforeEach(function () {
    $this->crmWorld();
});

afterEach(function () {
    Carbon::setTestNow();
});

function mk(object $t, string $who, string $method, string $uri, array $data = [], array $headers = []): TestResponse
{
    return $t->as($who)->withHeaders($headers)->json($method, '/api/v1/marketing/'.ltrim($uri, '/'), $data);
}

function met(object $t, string $who, string $method, string $uri, array $data = [], array $headers = []): TestResponse
{
    return $t->as($who)->withHeaders($headers)->json($method, '/api/v1/crm/'.ltrim($uri, '/'), $data);
}

function lockOf(object $t, string $table, string $code): array
{
    return (fn () => ['If-Match' => (string) $this->admin()->table($table)->where('tenant_id', $this->tenant->id)->where('code', $code)->value('lock_version')])->call($t);
}

/** Cliente de u03 con email y móvil. */
function mkClient(object $t, string $name, array $extra = []): Client
{
    return $t->makeClient('u03', $extra + ['name' => $name, 'email' => Str::slug($name).random_int(10, 99999).'@correo.test', 'phone' => '+51 9'.random_int(10000000, 99999999)]);
}

function grant(object $t, string $type, string $id, string $channel = 'email'): TestResponse
{
    return mk($t, 'admin', 'POST', 'consents', ['subject_type' => $type, 'subject_id' => $id, 'channel' => $channel, 'source' => 'Formulario web (casilla)',
        'evidence' => 'IP 190.0.0.1 · form web-123', 'policy_version' => 'PDP-2026.1']);
}

function segmentOf(object $t, array $rules = [], string $match = 'all', ?string $name = null): string
{
    return mk($t, 'manager', 'POST', 'segments', ['name' => $name ?? 'Segmento '.Str::random(6), 'match' => $match, 'rules' => $rules])->assertCreated()->json('data.code');
}

/** Campaña en borrador: email con TPL-01 o WhatsApp con la plantilla indicada. */
function campaignOf(object $t, string $segment, string $channel = 'email', ?string $template = null, array $extra = []): string
{
    return mk($t, 'manager', 'POST', 'campaigns', $extra + ['name' => 'Campaña '.Str::random(5), 'channel' => $channel, 'segment_id' => $segment,
        'template_id' => $template ?? 'TPL-01'])->assertCreated()->json('data.code');
}

function testSend(object $t, string $code): TestResponse
{
    return mk($t, 'manager', 'POST', "campaigns/{$code}/test-send", ['to_user_id' => (fn () => $this->users['manager']->id)->call($t)], $t->idem());
}

/** Plantilla WhatsApp comercial aprobada por Meta. */
function waTemplate(object $t): string
{
    $code = mk($t, 'manager', 'POST', 'templates', ['channel' => 'whatsapp', 'name' => 'promo_'.Str::lower(Str::random(5)), 'category' => 'MARKETING',
        'body' => 'Hola {{nombre}}, tenemos una oferta para {{destino}}. Responde BAJA para no recibir más mensajes.'])->assertCreated()->json('data.code');
    mk($t, 'manager', 'POST', "templates/{$code}/review", ['status' => 'aprobada'])->assertOk();

    return $code;
}

/** Reserva insertada directamente (fecha de creación controlada) para avance de metas y atribución. */
function reservationRow(object $t, string $owner, string $clientId, float $total, Carbon $createdAt, array $extra = []): string
{
    return (fn () => $this->admin()->table('booking_reservations')->insertGetId($extra + [
        'tenant_id' => $this->tenant->id, 'code' => 'RES-T-'.random_int(100000, 999999), 'client_id' => $clientId, 'owner_id' => $this->users[$owner]->id,
        'title' => 'Viaje de prueba', 'status' => 'confirmada', 'channel' => 'Directo', 'travel_from' => now()->addDays(30)->toDateString(),
        'travel_to' => now()->addDays(33)->toDateString(), 'currency' => 'PEN', 'fx_rate' => 1, 'total_amount' => $total, 'snapshot_label' => 'v1',
        'policies_snapshot' => '{}', 'created_at' => $createdAt, 'updated_at' => $createdAt,
    ]))->call($t);
}

function targetOf(object $t, string $who, array $data): TestResponse
{
    return met($t, 'manager', 'POST', 'sales-targets', $data + ['scope_type' => 'asesor', 'scope_id' => (fn () => $this->users[$who]->id)->call($t), 'period_type' => 'mes',
        'period_key' => now(LIMA)->format('Y-m'), 'goal_sales_pen' => 10000, 'activate' => true]);
}

/** Viaja en el tiempo y reemite los tokens (expiran por TTL). */
function travel(object $t, Carbon $at): void
{
    Carbon::setTestNow($at);
    (function () {
        foreach (array_keys($this->users) as $who) {
            $this->tokens[$who] = $this->tokenFor($this->users[$who]);
        }
    })->call($t);
}

it('§10-1 aislamiento: el tenant B no ve segmentos, campañas, consentimientos ni metas de A', function () {
    $client = mkClient($this, 'Ana Rivas');
    grant($this, 'client', $client->id)->assertCreated();
    $campaign = campaignOf($this, segmentOf($this));
    targetOf($this, 'u03', [])->assertCreated();

    $inka = $this->makeTenant('inka');
    $mgr = $this->tokenFor($this->makeUser($inka, 'manager'));
    expect($this->api($mgr)->getJson('/api/v1/marketing/segments')->assertOk()->json('data'))->toBe([])
        ->and($this->api($mgr)->getJson('/api/v1/marketing/campaigns')->assertOk()->json('data'))->toBe([])
        ->and($this->api($mgr)->getJson('/api/v1/marketing/consents')->assertOk()->json('data'))->toBe([])
        ->and($this->api($mgr)->getJson('/api/v1/crm/sales-targets?filter[period]=all')->assertOk()->json('data'))->toBe([]);
    $this->api($mgr)->getJson("/api/v1/marketing/campaigns/{$campaign}")->assertNotFound();
    $this->api($mgr)->getJson('/api/v1/crm/sales-targets/MET-0001')->assertNotFound();
});

it('§10-2 RBAC: Ventas → 403 en marketing; Operaciones y Finanzas → 403 en metas', function () {
    mk($this, 'u03', 'GET', 'campaigns')->assertForbidden();
    mk($this, 'u03', 'GET', 'segments')->assertForbidden();
    mk($this, 'u03', 'POST', 'consents', [])->assertForbidden();
    met($this, 'ops', 'GET', 'sales-targets')->assertForbidden();
    met($this, 'finance', 'GET', 'sales-targets')->assertForbidden();
    met($this, 'u03', 'POST', 'sales-targets', [])->assertForbidden();
    mk($this, 'manager', 'GET', 'campaigns')->assertOk();
    met($this, 'u03', 'GET', 'sales-targets')->assertOk();
});

it('§10-3 Ventas ve solo sus metas no borrador; meta ajena → 403; ranking sin montos ajenos', function () {
    $mine = targetOf($this, 'u03', ['goal_sales_pen' => 20000, 'bonus_amount' => 500])->assertCreated()->json('data.code');
    $other = targetOf($this, 'u04', ['goal_sales_pen' => 15000, 'bonus_amount' => 400])->assertCreated()->json('data.code');
    $draft = targetOf($this, 'u03', ['period_key' => now(LIMA)->addMonthNoOverflow()->format('Y-m'), 'activate' => false])->assertCreated()->json('data.code');

    $list = met($this, 'u03', 'GET', 'sales-targets?filter[period]=all')->assertOk()->json('data');
    expect(collect($list)->pluck('code')->all())->toBe([$mine]);
    met($this, 'u03', 'GET', "sales-targets/{$other}")->assertForbidden()->assertJsonPath('code', 'MET-BR-07');
    met($this, 'u03', 'GET', "sales-targets/{$draft}")->assertForbidden();
    met($this, 'u03', 'GET', "sales-targets/{$mine}")->assertOk()->assertJsonPath('data.goals.sales', 20000);

    $ranking = collect(met($this, 'u03', 'GET', 'sales-targets/ranking?period='.now(LIMA)->format('Y-m'))->assertOk()->json('data'));
    $me = $ranking->firstWhere('me', true);
    $peer = $ranking->firstWhere('me', false);
    expect($ranking)->toHaveCount(2)->and($me)->toHaveKeys(['sales', 'goal', 'bonus', 'pct', 'position'])
        ->and($peer)->toHaveKeys(['name', 'pct', 'position'])->and($peer)->not->toHaveKeys(['sales', 'goal', 'bonus']);
    expect(collect(met($this, 'manager', 'GET', 'sales-targets/ranking?period='.now(LIMA)->format('Y-m'))->json('data'))->every(fn ($r) => array_key_exists('sales', $r)))->toBeTrue();
});

it('§10-4 audiencia: baja, sin consentimiento, sin móvil y fatiga quedan suprimidos con su motivo; evaluación histórica con at', function () {
    $tpl = waTemplate($this);
    $optout = mkClient($this, 'Olga Baja');
    $noConsent = mkClient($this, 'Nora Sin');
    $landline = mkClient($this, 'Fijo Corporativo', ['phone' => '01 4455667']);
    $tired = mkClient($this, 'Tomas Fatiga');
    $ok = mkClient($this, 'Eva Elegible');

    travel($this, now()->subDays(2));
    $consent = grant($this, 'client', $optout->id, 'whatsapp')->assertCreated()->json('data.code');
    travel($this, now()->addDays(2));
    foreach ([$tired, $ok] as $c) {
        grant($this, 'client', $c->id, 'whatsapp')->assertCreated();
    }
    mk($this, 'admin', 'POST', "consents/{$consent}/revoke", ['source' => 'Solicitud ARCO'])->assertOk();

    // Fatiga: Tomás recibe otra campaña hoy.
    $first = campaignOf($this, segmentOf($this, [['f' => 'tag', 'op' => 'has', 'v' => 'fatiga']]), 'whatsapp', $tpl);
    $this->admin()->table('crm_clients')->where('id', $tired->id)->update(['tags' => '{fatiga}']);
    $this->admin()->table('iam_users')->where('id', $this->users['manager']->id)->update(['phone' => '+51 999 888 777']);
    testSend($this, $first)->assertOk();
    mk($this, 'manager', 'POST', "campaigns/{$first}/send", [], $this->idem())->assertOk();

    $all = campaignOf($this, segmentOf($this, [['f' => 'type', 'op' => 'eq', 'v' => 'client']]), 'whatsapp', $tpl);
    $a = mk($this, 'manager', 'GET', "campaigns/{$all}/audience")->assertOk()->json('data');
    $reason = fn (array $aud, string $name) => collect($aud['suppressed'])->firstWhere('name', $name)['reason'] ?? 'elegible';
    expect($reason($a, 'Olga Baja'))->toBe('optout')->and($reason($a, 'Nora Sin'))->toBe('consent')->and($reason($a, 'Fijo Corporativo'))->toBe('nodata')
        ->and($reason($a, 'Tomas Fatiga'))->toBe('fatigue')->and(collect($a['recipients'])->pluck('name')->all())->toBe(['Eva Elegible'])
        ->and($a['counts'])->toMatchArray(['optout' => 1, 'nodata' => 1, 'fatigue' => 1]);

    $past = mk($this, 'manager', 'GET', "campaigns/{$all}/audience?at=".urlencode(now()->subDay()->toIso8601String()))->assertOk()->json('data');
    expect($reason($past, 'Olga Baja'))->toBe('elegible')->and($reason($past, 'Eva Elegible'))->toBe('consent');
});

it('§10-5 programar sin prueba → 422 MKT-BR-04; editar el contenido invalida las pruebas', function () {
    $c = mkClient($this, 'Ana Prueba');
    grant($this, 'client', $c->id)->assertCreated();
    $code = campaignOf($this, segmentOf($this));
    $when = now(LIMA)->addDay()->setTime(10, 0)->toIso8601String();
    mk($this, 'manager', 'POST', "campaigns/{$code}/schedule", ['scheduled_at' => $when], lockOf($this, 'mkt_campaigns', $code))->assertStatus(422)->assertJsonPath('code', 'MKT-BR-04');
    testSend($this, $code)->assertOk();
    mk($this, 'manager', 'PUT', "campaigns/{$code}", ['body' => "Nuevo texto para {{nombre}}.\n{{enlace_baja}}"], lockOf($this, 'mkt_campaigns', $code))->assertOk();
    expect($this->admin()->table('mkt_campaign_tests')->whereNull('invalidated_at')->count())->toBe(0);
    mk($this, 'manager', 'POST', "campaigns/{$code}/schedule", ['scheduled_at' => $when], lockOf($this, 'mkt_campaigns', $code))->assertStatus(422)->assertJsonPath('code', 'MKT-BR-04');
    testSend($this, $code)->assertOk();
    mk($this, 'manager', 'POST', "campaigns/{$code}/schedule", ['scheduled_at' => $when], lockOf($this, 'mkt_campaigns', $code))->assertOk()->assertJsonPath('data.status', 'programada');
    mk($this, 'manager', 'PUT', "campaigns/{$code}", ['name' => 'Otra'], lockOf($this, 'mkt_campaigns', $code))->assertStatus(409)->assertJsonPath('code', 'MKT-BR-08');
    // MKT-BR-02: el cuerpo comercial exige el enlace de baja.
    mk($this, 'manager', 'POST', 'campaigns', ['name' => 'Sin baja', 'channel' => 'email', 'segment_id' => segmentOf($this), 'template_id' => 'TPL-01', 'body' => 'Hola'])
        ->assertStatus(422)->assertJsonPath('code', 'MKT-BR-02');
});

it('§10-6 WhatsApp con plantilla en revisión: probar, programar y enviar → 422 MKT-BR-03', function () {
    $c = mkClient($this, 'Wanda Whats');
    grant($this, 'client', $c->id, 'whatsapp')->assertCreated();
    $tpl = waTemplate($this);
    $code = campaignOf($this, segmentOf($this), 'whatsapp', $tpl);
    // Editar una plantilla aprobada la devuelve a revisión de Meta.
    mk($this, 'manager', 'PUT', "templates/{$tpl}", ['body' => 'Hola {{nombre}}, nueva oferta. Responde BAJA para no recibir más.'], lockOf($this, 'mkt_templates', $tpl))
        ->assertOk()->assertJsonPath('data.status', 'en_revision');
    testSend($this, $code)->assertStatus(422)->assertJsonPath('code', 'MKT-BR-03');
    mk($this, 'manager', 'POST', "campaigns/{$code}/schedule", ['scheduled_at' => now(LIMA)->addDay()->setTime(10, 0)->toIso8601String()], lockOf($this, 'mkt_campaigns', $code))
        ->assertStatus(422)->assertJsonPath('code', 'MKT-BR-03');
    mk($this, 'manager', 'POST', "campaigns/{$code}/send", [], $this->idem())->assertStatus(422)->assertJsonPath('code', 'MKT-BR-03');
    mk($this, 'manager', 'POST', 'automations/AUT-02/activate', [], lockOf($this, 'mkt_automations', 'AUT-02'))->assertStatus(422)->assertJsonPath('code', 'MKT-BR-03');
});

it('§10-7 programar con menos de 15 min o WhatsApp a las 21:00 → 422 MKT-BR-05', function () {
    $c = mkClient($this, 'Hora Lima');
    grant($this, 'client', $c->id, 'whatsapp')->assertCreated();
    $code = campaignOf($this, segmentOf($this), 'whatsapp', waTemplate($this));
    testSend($this, $code)->assertStatus(422)->assertJsonPath('code', 'MKT-BR-18'); // el gerente de prueba no tiene móvil
    $this->admin()->table('iam_users')->where('id', $this->users['manager']->id)->update(['phone' => '+51 999 888 777']);
    testSend($this, $code)->assertOk();
    $lock = fn () => lockOf($this, 'mkt_campaigns', $code);
    mk($this, 'manager', 'POST', "campaigns/{$code}/schedule", ['scheduled_at' => now()->addMinutes(5)->toIso8601String()], $lock())->assertStatus(422)->assertJsonPath('code', 'MKT-BR-05');
    mk($this, 'manager', 'POST', "campaigns/{$code}/schedule", ['scheduled_at' => now(LIMA)->addDay()->setTime(21, 0)->toIso8601String()], $lock())->assertStatus(422)->assertJsonPath('code', 'MKT-BR-05');
    mk($this, 'manager', 'POST', "campaigns/{$code}/schedule", ['scheduled_at' => now(LIMA)->addDay()->setTime(19, 30)->toIso8601String()], $lock())->assertOk();
});

it('§10-8 envío idempotente con la misma Idempotency-Key; enviada → programada es 409', function () {
    foreach (['Uno Envio', 'Dos Envio'] as $name) {
        grant($this, 'client', mkClient($this, $name)->id)->assertCreated();
    }
    $code = campaignOf($this, segmentOf($this));
    testSend($this, $code)->assertOk();
    $key = $this->idem('send-'.$code);
    $first = mk($this, 'manager', 'POST', "campaigns/{$code}/send", [], $key)->assertOk()->json();
    $second = mk($this, 'manager', 'POST', "campaigns/{$code}/send", [], $key)->assertOk()->json();
    expect($second)->toBe($first);
    $campaignId = $this->admin()->table('mkt_campaigns')->where('code', $code)->value('id');
    expect($this->admin()->table('mkt_campaign_recipients')->where('campaign_id', $campaignId)->count())->toBe(2)
        ->and($this->admin()->table('mkt_campaigns')->where('id', $campaignId)->value('status'))->toBe('enviada')
        ->and($this->admin()->table('notif_messages')->where('event', 'marketing.campana')->count())->toBe(2);
    mk($this, 'manager', 'POST', "campaigns/{$code}/send", [], $this->idem())->assertStatus(409)->assertJsonPath('code', 'MKT-BR-08');
    mk($this, 'manager', 'POST', "campaigns/{$code}/schedule", ['scheduled_at' => now()->addDay()->toIso8601String()], lockOf($this, 'mkt_campaigns', $code))
        ->assertStatus(409)->assertJsonPath('code', 'MKT-BR-08');
    $detail = mk($this, 'manager', 'GET', "campaigns/{$code}")->assertOk()->json('data');
    expect($detail['metrics']['sent'])->toBe(2)->and($detail['snapshot']['eligible'])->toBe(2);
});

it('§10-9 respuesta «BAJA» por WhatsApp revoca el consentimiento y excluye al contacto de la campaña siguiente', function () {
    $tpl = waTemplate($this);
    $bea = mkClient($this, 'Bea Baja', ['phone' => '+51 912 345 678']);
    $leo = mkClient($this, 'Leo Sigue');
    foreach ([$bea, $leo] as $c) {
        grant($this, 'client', $c->id, 'whatsapp')->assertCreated();
    }
    $segment = segmentOf($this);
    $code = campaignOf($this, $segment, 'whatsapp', $tpl);
    $this->admin()->table('iam_users')->where('id', $this->users['manager']->id)->update(['phone' => '+51 999 888 777']);
    testSend($this, $code)->assertOk();
    mk($this, 'manager', 'POST', "campaigns/{$code}/schedule", ['scheduled_at' => now(LIMA)->addDay()->setTime(10, 0)->toIso8601String()], lockOf($this, 'mkt_campaigns', $code))->assertOk();

    $payload = ['entry' => [['changes' => [['value' => ['contacts' => [['wa_id' => '51912345678', 'profile' => ['name' => 'Bea']]],
        'messages' => [['from' => '51912345678', 'id' => 'wamid.baja-1', 'timestamp' => (string) now()->timestamp, 'text' => ['body' => 'BAJA']]]]]]]]];
    $this->shop('POST', 'integrations/whatsapp/webhooks/messages', $payload, ['X-Hub-Signature-256' => 'sha256=malo'])->assertStatus(401);
    $this->shop('POST', 'integrations/whatsapp/webhooks/messages', $payload, ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', json_encode($payload), 'whatsapp-sandbox-secret')])->assertOk();
    expect($this->admin()->table('mkt_consents')->where('subject_id', $bea->id)->value('status'))->toBe('revocado')
        ->and($this->admin()->table('mkt_consent_events')->where('event', 'revocado')->value('source'))->toBe('Respuesta BAJA en WhatsApp')
        ->and($this->admin()->table('notif_messages')->where('event', 'marketing.baja')->where('channel', 'whatsapp')->count())->toBe(1);

    // La campaña programada se dispara (JOB-16) y Bea queda suprimida por baja.
    $this->admin()->table('mkt_campaigns')->where('code', $code)->update(['scheduled_at' => now()->subMinute()]);
    $this->artisan('marketing:run', ['job' => 'dispatch-scheduled', '--tenant' => $this->tenant->id])->assertSuccessful();
    $id = $this->admin()->table('mkt_campaigns')->where('code', $code)->value('id');
    expect($this->admin()->table('mkt_campaign_suppressions')->where('campaign_id', $id)->where('subject_id', $bea->id)->value('reason'))->toBe('optout')
        ->and($this->admin()->table('mkt_campaign_recipients')->where('campaign_id', $id)->pluck('name')->all())->toBe(['Leo Sigue']);
});

it('§10-10 atribución last-touch 30 días: reserva a los 10 días sí; desde cotización previa no; la campaña posterior se lleva la conversión', function () {
    $x = mkClient($this, 'Xime Atribuida');
    $q = mkClient($this, 'Quique Cotizado');
    $z = mkClient($this, 'Zoe Doble');
    foreach ([$x, $q, $z] as $c) {
        grant($this, 'client', $c->id)->assertCreated();
    }
    $first = campaignOf($this, segmentOf($this));
    testSend($this, $first)->assertOk();
    mk($this, 'manager', 'POST', "campaigns/{$first}/send", [], $this->idem())->assertOk();
    $sent = now()->subDays(20);
    $firstId = $this->admin()->table('mkt_campaigns')->where('code', $first)->value('id');
    $this->admin()->table('mkt_campaigns')->where('id', $firstId)->update(['sent_at' => $sent]);
    $this->admin()->table('mkt_campaign_recipients')->where('campaign_id', $firstId)->update(['sent_at' => $sent]);

    $second = campaignOf($this, segmentOf($this, [['f' => 'country', 'op' => 'eq', 'v' => 'Chile']]));
    $this->admin()->table('crm_clients')->where('id', $z->id)->update(['country' => 'Chile']);
    testSend($this, $second)->assertOk();
    mk($this, 'manager', 'POST', "campaigns/{$second}/send", [], $this->idem())->assertOk();
    $secondId = $this->admin()->table('mkt_campaigns')->where('code', $second)->value('id');
    $this->admin()->table('mkt_campaigns')->where('id', $secondId)->update(['sent_at' => now()->subDays(8)]);
    $this->admin()->table('mkt_campaign_recipients')->where('campaign_id', $secondId)->update(['sent_at' => now()->subDays(8)]);

    $rx = reservationRow($this, 'u03', $x->id, 1500, now()->subDays(10));
    $quote = $this->admin()->table('quote_quotes')->insertGetId(['tenant_id' => $this->tenant->id, 'code' => 'COT-T-1', 'client_id' => $q->id, 'owner_id' => $this->users['u03']->id,
        'title' => 'Corporativo', 'travel_from' => now()->addDays(40)->toDateString(), 'travel_to' => now()->addDays(42)->toDateString(), 'valid_until' => now()->addDays(10)->toDateString(),
        'currency' => 'PEN', 'fx_rate' => 1, 'pax_adt' => 2, 'created_at' => now()->subDays(25), 'updated_at' => now()->subDays(25)]);
    reservationRow($this, 'u03', $q->id, 9000, now()->subDays(5), ['quote_id' => $quote]);
    $rz = reservationRow($this, 'u03', $z->id, 400, now()->subDays(5), ['currency' => 'USD', 'fx_rate' => 3.8]);
    reservationRow($this, 'u03', $x->id, 700, now()->subDays(1), ['status' => 'cancelada']);

    $this->artisan('marketing:run', ['job' => 'attribution', '--tenant' => $this->tenant->id])->assertSuccessful();
    $att = $this->admin()->table('mkt_attributions')->where('kind', 'reservation')->get()->keyBy('reservation_id');
    expect($att)->toHaveCount(2)->and($att[$rx]->campaign_id)->toBe($firstId)->and($att[$rz]->campaign_id)->toBe($secondId)
        ->and($att[$rz]->revenue_pen)->toBe('1520.00');
    expect(mk($this, 'manager', 'GET', "campaigns/{$first}")->json('data.metrics'))->toMatchArray(['reservations' => 1, 'revenue_pen' => 1500]);
});

it('§10-11 automatizaciones: no reenvía al ejecutar dos veces; comercial sin consentimiento → suprimido; pre-viaje sin baja → enviado', function () {
    $this->inTenant(fn () => Subscriber::create(['code' => app(SequenceService::class)->next('mkt_subscriber'), 'full_name' => 'Sara Suscrita', 'email' => 'sara@correo.test',
        'source' => 'Newsletter web']));
    $traveler = mkClient($this, 'Pablo Viajero', ['phone' => '+51 955 111 222']);
    reservationRow($this, 'u03', $traveler->id, 800, now()->subDays(10), ['travel_from' => now(LIMA)->addDays(2)->toDateString()]);
    mk($this, 'manager', 'POST', 'templates/TPL-03/review', ['status' => 'aprobada'])->assertOk();

    foreach (['AUT-01', 'AUT-03'] as $aut) {
        mk($this, 'manager', 'POST', "automations/{$aut}/activate", [], lockOf($this, 'mkt_automations', $aut))->assertOk();
    }
    $queues = collect(mk($this, 'manager', 'GET', 'automations')->assertOk()->json('data'))->keyBy('code');
    expect($queues['AUT-01']['queue'][0])->toMatchArray(['name' => 'Sara Suscrita', 'reason' => 'consent'])
        ->and($queues['AUT-03']['queue'][0])->toMatchArray(['name' => 'Pablo Viajero', 'reason' => null]);

    mk($this, 'manager', 'POST', 'automations/AUT-01/run', [], $this->idem())->assertOk()->assertJsonPath('data.suppressed', 1)->assertJsonPath('data.sent', 0);
    mk($this, 'manager', 'POST', 'automations/AUT-03/run', [], $this->idem())->assertOk()->assertJsonPath('data.sent', 1);
    mk($this, 'manager', 'POST', 'automations/AUT-03/run', [], $this->idem())->assertOk()->assertJsonPath('data.queued', 0)->assertJsonPath('data.sent', 0);
    expect($this->admin()->table('mkt_automation_enrollments')->count())->toBe(2)
        ->and($this->admin()->table('notif_messages')->where('event', 'marketing.auto.pretrip')->count())->toBe(1)
        ->and($this->admin()->table('mkt_automations')->where('code', 'AUT-03')->value('sent_total'))->toBe(1);

    // Con baja, el pre-viaje (servicio) también se suprime.
    $client = mkClient($this, 'Rita Baja', ['phone' => '+51 955 333 444']);
    reservationRow($this, 'u03', $client->id, 500, now()->subDays(3), ['travel_from' => now(LIMA)->addDay()->toDateString()]);
    $consent = grant($this, 'client', $client->id, 'whatsapp')->json('data.code');
    mk($this, 'admin', 'POST', "consents/{$consent}/revoke", ['source' => 'Solicitud verbal'])->assertOk();
    mk($this, 'manager', 'POST', 'automations/AUT-03/run', [], $this->idem())->assertOk()->assertJsonPath('data.suppressed', 1);
});

it('§10-12 un segmento usado por una campaña no se elimina (409 MKT-BR-12); se puede duplicar', function () {
    $segment = segmentOf($this, [['f' => 'nps', 'op' => 'gte', 'v' => 9]], 'all', 'Promotores');
    campaignOf($this, $segment);
    mk($this, 'manager', 'DELETE', "segments/{$segment}")->assertStatus(409)->assertJsonPath('code', 'MKT-BR-12');
    $copy = mk($this, 'manager', 'POST', "segments/{$segment}/duplicate")->assertCreated()->json('data');
    expect($copy['name'])->toBe('Promotores (copia)')->and($copy['rules_text'])->toBe('NPS ≥ 9');
    mk($this, 'manager', 'DELETE', "segments/{$copy['code']}")->assertOk();
    mk($this, 'manager', 'POST', 'segments', ['name' => 'promotores', 'rules' => []])->assertStatus(422);
    mk($this, 'manager', 'POST', 'segments', ['name' => 'Mal', 'rules' => [['f' => 'dni', 'op' => 'eq', 'v' => 'x']]])->assertStatus(422);
});

it('§10-13 meta duplicada por alcance y período → 409 MET-BR-01; período terminado → 422 MET-BR-03; ventas 0 → 422 MET-BR-02', function () {
    targetOf($this, 'u03', [])->assertCreated();
    targetOf($this, 'u03', ['activate' => false])->assertStatus(409)->assertJsonPath('code', 'MET-BR-01');
    targetOf($this, 'u03', ['period_key' => now(LIMA)->subMonthNoOverflow()->format('Y-m')])->assertStatus(422)->assertJsonPath('code', 'MET-BR-03');
    targetOf($this, 'u04', ['goal_sales_pen' => 0])->assertStatus(422)->assertJsonPath('code', 'MET-BR-02');
    targetOf($this, 'u04', ['goal_conversion_pct' => 120])->assertStatus(422)->assertJsonPath('code', 'MET-BR-02');
    $draft = targetOf($this, 'u04', ['activate' => false])->assertCreated()->json('data.code');
    met($this, 'manager', 'POST', "sales-targets/{$draft}/void", ['reason' => 'Error de carga'], lockOf($this, 'crm_sales_targets', $draft))->assertOk();
    targetOf($this, 'u04', [])->assertCreated(); // la anulada no cuenta para MET-BR-01
});

it('§10-14 reducir una meta vigente al 97 % del período: Manager → 403 MET-BR-05; Tenant Admin → OK con ajuste auditado', function () {
    travel($this, now(LIMA)->endOfMonth()->subDay()->setTime(12, 0)->utc());
    $code = targetOf($this, 'u03', ['goal_sales_pen' => 30000])->assertCreated()->json('data.code');
    met($this, 'manager', 'POST', "sales-targets/{$code}/adjust", ['goal_sales_pen' => 25000], lockOf($this, 'crm_sales_targets', $code))->assertStatus(422)->assertJsonPath('code', 'MET-BR-04');
    met($this, 'manager', 'POST', "sales-targets/{$code}/adjust", ['goal_sales_pen' => 25000, 'reason' => 'Feriado largo'], lockOf($this, 'crm_sales_targets', $code))
        ->assertForbidden()->assertJsonPath('code', 'MET-BR-05');
    met($this, 'manager', 'POST', "sales-targets/{$code}/adjust", ['goal_sales_pen' => 32000, 'reason' => 'Campaña adicional'], lockOf($this, 'crm_sales_targets', $code))->assertOk();
    met($this, 'admin', 'POST', "sales-targets/{$code}/adjust", ['goal_sales_pen' => 25000, 'reason' => 'Feriado largo'], lockOf($this, 'crm_sales_targets', $code))->assertOk();
    $adj = $this->admin()->table('crm_sales_target_adjustments')->where('action', 'ajustar')->orderBy('occurred_at')->get();
    expect($adj)->toHaveCount(2)->and(json_decode($adj[1]->before, true)['goal_sales_pen'])->toEqual(32000)->and(json_decode($adj[1]->after, true)['goal_sales_pen'])->toEqual(25000)
        ->and($adj[1]->reason)->toBe('Feriado largo')->and($this->auditRows('Meta comercial', $code))->toHaveCount(3);
});

it('§10-15 cierre: antes del fin → 422 MET-BR-06; 112,9 % con bono S/ 600 → comisión «Bono por meta» calculada; repetir no duplica', function () {
    $start = now(LIMA)->startOfMonth()->setTime(10, 0);
    travel($this, $start->copy()->utc());
    $code = targetOf($this, 'u03', ['goal_sales_pen' => 10000, 'bonus_amount' => 600])->assertCreated()->json('data.code');
    $client = mkClient($this, 'Cliente Meta');
    reservationRow($this, 'u03', $client->id, 6290, $start->copy()->addHours(2)->utc());
    reservationRow($this, 'u03', $client->id, 5000, $start->copy()->addDays(3)->utc());
    met($this, 'manager', 'POST', "sales-targets/{$code}/close", [], $this->idem())->assertStatus(422)->assertJsonPath('code', 'MET-BR-06');

    travel($this, $start->copy()->addMonthNoOverflow()->addDay()->utc());
    $closed = met($this, 'manager', 'POST', "sales-targets/{$code}/close", [], $this->idem())->assertOk()->json('data');
    expect($closed['result']['pct'])->toEqual(112.9)->and($closed['commission']['amount'])->toBe('600.00');
    met($this, 'manager', 'POST', "sales-targets/{$code}/close", [], $this->idem())->assertStatus(409);
    $commissions = $this->admin()->table('fin_commissions')->where('kind', 'bono_meta')->get();
    expect($commissions)->toHaveCount(1)->and($commissions[0]->status)->toBe('calculada')->and($commissions[0]->reservation_id)->toBeNull();
    // El resultado queda congelado: una reserva posterior del período no lo altera.
    reservationRow($this, 'u03', $client->id, 9999, $start->copy()->addDays(5)->utc());
    met($this, 'manager', 'GET', "sales-targets/{$code}")->assertOk()->assertJsonPath('data.actual.sales', 11290)->assertJsonPath('data.health', 'cumplida');
    // Finanzas la ve en Comisiones como «Bono por meta» y la aprueba (PROC-047).
    $this->qGet('finance', "finance/commissions/{$commissions[0]->code}")->assertOk()->assertJsonPath('data.kind_label', 'Bono por meta')->assertJsonPath('data.sales_target', $code);
    $this->qPost('finance', "finance/commissions/{$commissions[0]->code}/approve")->assertOk();
});

it('§10-16 avance: ventas del asesor en PEN con el TC de la reserva; sede por sede de la reserva; equipo por integrantes vigentes', function () {
    $client = mkClient($this, 'Cliente Avance');
    $sede = $this->inTenant(fn () => Sede::value('id'));
    reservationRow($this, 'u03', $client->id, 1000, now());
    reservationRow($this, 'u03', $client->id, 100, now(), ['currency' => 'USD', 'fx_rate' => 3.8, 'sede_id' => $sede]);
    reservationRow($this, 'u03', $client->id, 500, now(), ['status' => 'cancelada']);
    reservationRow($this, 'u03', $client->id, 300, now(), ['status' => 'borrador']);
    reservationRow($this, 'u03', $client->id, 900, now(LIMA)->startOfMonth()->subDay()->utc());
    reservationRow($this, 'u04', $client->id, 250, now(), ['sede_id' => $sede]);

    $mine = targetOf($this, 'u03', [])->assertCreated()->json('data.code');
    met($this, 'manager', 'GET', "sales-targets/{$mine}")->assertOk()->assertJsonPath('data.actual.sales', 1380)->assertJsonPath('data.actual.bookings', 2);
    $bySede = met($this, 'manager', 'POST', 'sales-targets', ['scope_type' => 'sede', 'scope_id' => $sede, 'period_type' => 'mes', 'period_key' => now(LIMA)->format('Y-m'),
        'goal_sales_pen' => 5000, 'activate' => true])->assertCreated()->json('data.code');
    met($this, 'manager', 'GET', "sales-targets/{$bySede}")->assertOk()->assertJsonPath('data.actual.sales', 630);

    met($this, 'manager', 'PUT', 'sales-teams', ['name' => 'Equipo Lima', 'leader_id' => $this->users['manager']->id, 'members' => [$this->users['u03']->id, $this->users['u04']->id]])->assertOk();
    $this->admin()->table('crm_sales_team_members')->where('user_id', $this->users['u04']->id)->update(['valid_from' => now()->subYear(), 'valid_to' => now(LIMA)->startOfMonth()->subDays(2)]);
    $team = met($this, 'manager', 'POST', 'sales-targets', ['scope_type' => 'equipo', 'scope_id' => 'EQ-01', 'period_type' => 'mes', 'period_key' => now(LIMA)->format('Y-m'),
        'goal_sales_pen' => 8000, 'bonus_amount' => 100, 'activate' => true])->assertStatus(422);
    $team = met($this, 'manager', 'POST', 'sales-targets', ['scope_type' => 'equipo', 'scope_id' => 'EQ-01', 'period_type' => 'mes', 'period_key' => now(LIMA)->format('Y-m'),
        'goal_sales_pen' => 8000, 'activate' => true])->assertCreated()->json('data.code');
    met($this, 'manager', 'GET', "sales-targets/{$team}")->assertOk()->assertJsonPath('data.actual.sales', 1380);
});

it('baja pública por enlace firmado: idempotente y sin sesión', function () {
    $c = mkClient($this, 'Pia Enlace');
    grant($this, 'client', $c->id)->assertCreated();
    $token = $this->inTenant(fn () => app(MessageRenderer::class)->token('client', $c->id, 'email'));
    $this->getJson("/api/v1/public/unsubscribe/{$token}")->assertOk()->assertJsonPath('data.status', 'otorgado');
    $this->postJson("/api/v1/public/unsubscribe/{$token}")->assertOk()->assertJsonPath('data.status', 'revocado');
    $this->postJson("/api/v1/public/unsubscribe/{$token}")->assertOk()->assertJsonPath('data.status', 'revocado');
    $this->getJson('/api/v1/public/unsubscribe/x'.$token)->assertNotFound();
    expect($this->admin()->table('mkt_consent_events')->where('event', 'revocado')->count())->toBe(1);
    // MKT-BR-11: el historial es inmutable.
    expect(fn () => $this->admin()->table('mkt_consent_events')->delete())->toThrow(QueryException::class);
    expect(Tenant::count())->toBe(1);
});
