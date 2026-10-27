<?php

use App\Exceptions\ApiException;
use App\Jobs\EmitPlatformInvoiceJob;
use App\Services\Auth\TokenIssuer;
use App\Services\Integrations\WebhookService;
use App\Services\Integrations\WebhookSigner;
use App\Services\Platform\CouponService;
use App\Services\Platform\DowngradeValidator;
use App\Services\Platform\DunningService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\LimitService;
use App\Services\Platform\MetricsService;
use App\Services\Platform\PlatformCatalog;
use App\Services\Platform\PlatformInvoiceService;
use App\Services\Platform\ProrationCalculator;
use App\Services\Platform\UsageMeter;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
| Spec 13 §10: casos mínimos de suscripción, facturación de plataforma, entitlements, límites y API pública (1–12).
*/

beforeEach(function () {
    $this->crmWorld();
    $this->sa = createPlatformUser();
    $this->saToken = app(TokenIssuer::class)->issue($this->sa, false, null, '127.0.0.1')->plainTextToken;
    $this->tid = $this->tenant->id;
    $this->sub = fn () => DB::connection('pgsql_admin')->table('subscriptions')->where('tenant_id', $this->tid)->first();
});

function plat(): Connection
{
    return DB::connection('pgsql_admin');
}

/** Fija el plan del tenant (catálogo) y de su suscripción, y limpia la caché de entitlements. */
function setPlan(object $t, string $plan): void
{
    plat()->table('tenants')->where('id', $t->tid)->update(['plan_id' => $plan]);
    plat()->table('subscriptions')->where('tenant_id', $t->tid)->update(['plan_id' => $plan]);
    EntitlementService::forget($t->tid);
}

function addCard(object $t, string $last4 = '4242'): void
{
    plat()->table('payment_methods')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $t->tid, 'gateway_token' => "tok_sandbox_visa_{$last4}_abc123",
        'brand' => 'visa', 'last4' => $last4, 'exp_month' => 12, 'exp_year' => 2030, 'holder' => 'Andes Admin', 'is_default' => true, 'status' => 'active', 'created_at' => now()]);
}

function makeCoupon(array $overrides = []): object
{
    $row = $overrides + ['id' => (string) Str::uuid7(), 'code' => 'PRUEBA'.Str::upper(Str::random(4)), 'type' => 'percent', 'value' => 20, 'duration_cycles' => 3,
        'plan_ids' => '[]', 'max_redemptions' => 10, 'redeemed_count' => 0, 'valid_to' => now()->addMonth()->toDateString(), 'status' => 'active', 'created_at' => now()];
    plat()->table('coupons')->insert($row);

    return (object) $row;
}

function apiKey(object $t, array $data = []): array
{
    return $t->as('admin')->postJson('/api/v1/integrations/api-keys', $data + ['name' => 'ERP', 'environment' => 'live', 'scopes' => ['reservations:read']])->assertCreated()->json('data');
}

function ext(object $t, string $token, string $uri = 'reservations'): TestResponse
{
    return (fn () => $this->api($token)->getJson('/api/v1/ext/'.$uri))->call($t);
}

it('§10-1 prorrateo: Enterprise mensual → anual con 4/30 días; un neto negativo va a credit_balance', function () {
    $p = ProrationCalculator::compute('2890.00', 'monthly', '2890.00', '28900.00', 'annual',
        Carbon::parse('2026-10-01'), Carbon::parse('2026-10-30'), Carbon::parse('2026-10-27'));
    expect($p)->toMatchArray(['days_left' => 4, 'period_days' => 30, 'charge' => '28900.00', 'credit' => '385.33', 'net' => '28514.67', 'igv' => '5132.64',
        'total' => '33647.31', 'new_period' => true, 'period_start' => '2026-10-27', 'period_end' => '2027-10-26']);

    // Con el precio pactado mayor que el nuevo, el neto es negativo: el saldo queda a favor y no se factura.
    plat()->table('subscriptions')->where('tenant_id', $this->tenant->id)->update(['plan_id' => 'professional', 'negotiated_price' => 20000,
        'current_period_start' => now('America/Lima')->subDays(5)->toDateString(), 'current_period_end' => now('America/Lima')->addDays(24)->toDateString()]);
    setPlan($this, 'professional');
    $before = plat()->table('platform_invoices')->count();
    $sub = ($this->sub)();
    $this->as('admin')->withHeaders($this->idem() + ['If-Match' => (string) $sub->lock_version])
        ->postJson('/api/v1/billing/subscription/change', ['plan' => 'enterprise', 'cycle' => 'monthly'])->assertOk();
    expect((float) ($this->sub)()->credit_balance)->toBeGreaterThan(0)->and(plat()->table('platform_invoices')->count())->toBe($before)
        ->and(($this->sub)()->plan_id)->toBe('enterprise');
});

it('§10-2 downgrade: con 3 sedes y 38.2 GB → 422 con bloqueos; dentro del límite → cambio programado al fin del periodo', function () {
    foreach (['Arequipa', 'Puno'] as $city) {
        $this->as('admin')->postJson('/api/v1/iam/sedes', ['name' => $city, 'city' => $city, 'address' => 'Plaza 1'])->assertCreated();
    }
    plat()->table('tenants')->where('id', $this->tenant->id)->update(['storage_used_bytes' => (int) (38.2 * 1024 ** 3)]);
    $sub = ($this->sub)();
    $res = $this->as('admin')->withHeaders($this->idem() + ['If-Match' => (string) $sub->lock_version])
        ->postJson('/api/v1/billing/subscription/change', ['plan' => 'professional', 'cycle' => 'monthly'])
        ->assertUnprocessable()->assertJsonPath('error', 'SUB_DOWNGRADE_BLOCKED');
    expect(collect($res->json('blockers'))->pluck('key')->all())->toContain('sedes', 'storage_gb');

    plat()->table('tenants')->where('id', $this->tenant->id)->update(['storage_used_bytes' => 10 * 1024 ** 3]);
    plat()->table('iam_sedes')->where('tenant_id', $this->tenant->id)->whereIn('name', ['Arequipa', 'Puno'])->update(['status' => 'inactiva']);
    plat()->table('integration_adapters')->where('tenant_id', $this->tenant->id)->update(['status' => 'por_validar']);
    expect(app(DowngradeValidator::class)->blockers($this->tenant->id, 'professional'))->toBe([]);
    $this->as('admin')->withHeaders($this->idem() + ['If-Match' => (string) ($this->sub)()->lock_version])
        ->postJson('/api/v1/billing/subscription/change', ['plan' => 'professional', 'cycle' => 'monthly'])->assertOk();
    $change = plat()->table('subscription_changes')->where('subscription_id', $sub->id)->first();
    expect($change->status)->toBe('scheduled')
        ->and(substr((string) $change->effective_at, 0, 10))->toBe(Carbon::parse($sub->current_period_end)->addDay()->toDateString());
});

it('§10-3 dunning: rechazo el 26/09 → reintentos 27/09, 29/09 y 03/10; el tercero suspende; el pago manual reactiva', function () {
    $this->travelTo(Carbon::parse('2026-09-26 09:00', 'America/Lima'));
    addCard($this, '0002');
    $service = app(PlatformInvoiceService::class);
    $invoice = $service->issue(($this->sub)(), 'subscription', [['Enterprise mensual', 1, '2890.00', 'plan']], '2026-09-26', '2026-10-25');
    $service->charge($invoice, 'initial', "inv:{$invoice->id}:attempt:1");
    $at = fn () => Carbon::parse(plat()->table('platform_invoices')->where('id', $invoice->id)->value('dunning_next_retry_at'))->setTimezone('America/Lima')->format('d/m H:i');
    expect($at())->toBe('27/09 10:00');

    // El reintento manual no mueve el calendario.
    app(DunningService::class)->retryNow($service->find($invoice->id), 'manual-1', $this->sa);
    expect($at())->toBe('27/09 10:00');

    $this->travelTo(Carbon::parse('2026-09-27 10:05', 'America/Lima'));
    expect(app(DunningService::class)->run()[0]['result'])->toBe('rescheduled')->and($at())->toBe('29/09 10:00');
    $this->travelTo(Carbon::parse('2026-09-29 10:05', 'America/Lima'));
    app(DunningService::class)->run();
    expect($at())->toBe('03/10 10:00');
    $this->travelTo(Carbon::parse('2026-10-03 10:05', 'America/Lima'));
    expect(app(DunningService::class)->run()[0]['result'])->toBe('suspended');
    $tenant = plat()->table('tenants')->where('id', $this->tenant->id)->first();
    expect($tenant->status)->toBe('suspendido')->and($tenant->suspension_source)->toBe('billing');
    $this->as('manager')->getJson('/api/v1/crm/leads')->assertStatus(402);

    app(DunningService::class)->manualPayment($service->find($invoice->id), 'OP-551', 'transferencia', 'pm-1', $this->sa);
    expect(plat()->table('tenants')->where('id', $this->tenant->id)->value('status'))->toBe('activo')
        ->and(($this->sub)()->status)->toBe('active')
        ->and(plat()->table('platform_invoices')->where('id', $invoice->id)->value('status'))->toBe('paid');
});

it('§10-4 idempotencia: dos pagos con la misma Idempotency-Key → un solo cargo y un solo intento', function () {
    addCard($this, '0002');
    $service = app(PlatformInvoiceService::class);
    $invoice = $service->issue(($this->sub)(), 'subscription', [['Enterprise mensual', 1, '2890.00', 'plan']], now()->toDateString(), now()->addMonth()->toDateString());
    $service->charge($invoice, 'initial', "inv:{$invoice->id}:attempt:1");
    plat()->table('payment_methods')->where('tenant_id', $this->tenant->id)->update(['gateway_token' => 'tok_sandbox_visa_4242_ok1']);
    $number = $service->number($invoice);
    $this->as('admin')->postJson("/api/v1/billing/invoices/{$number}/pay")->assertStatus(400);
    foreach ([1, 2] as $_) {
        $this->as('admin')->withHeaders(['Idempotency-Key' => 'pago-unico-1'])->postJson("/api/v1/billing/invoices/{$number}/pay")
            ->assertOk()->assertJsonPath('data.paid', true);
    }
    expect(plat()->table('payment_attempts')->where('invoice_id', $invoice->id)->where('source', 'portal')->count())->toBe(1)
        ->and(plat()->table('payment_attempts')->where('invoice_id', $invoice->id)->where('result', 'succeeded')->count())->toBe(1);
});

it('§10-5 cupones: vencido, agotado, de otro plan o acumulado → 422; del último cupo solo prospera un canje', function () {
    $redeem = fn (string $code) => $this->as('admin')->postJson('/api/v1/billing/coupon', ['code' => $code]);
    $redeem(makeCoupon(['code' => 'VENCIDO1', 'valid_to' => now()->subDay()->toDateString()])->code)->assertUnprocessable()->assertJsonPath('error', 'COUPON_INVALID');
    $redeem(makeCoupon(['code' => 'AGOTADO1', 'max_redemptions' => 1, 'redeemed_count' => 1])->code)->assertUnprocessable()->assertJsonPath('error', 'COUPON_INVALID');
    $redeem(makeCoupon(['code' => 'SOLOSTART', 'plan_ids' => '["starter"]'])->code)->assertUnprocessable()->assertJsonPath('error', 'COUPON_INVALID');
    $redeem(makeCoupon(['code' => 'BIENVENIDA'])->code)->assertOk();
    $redeem(makeCoupon(['code' => 'OTRO25'])->code)->assertUnprocessable()->assertJsonPath('message', 'Los cupones no son acumulables: ya tiene un descuento vigente.');
    expect(plat()->table('audit_events')->where('tenant_id', $this->tenant->id)->where('action', 'cupon_rechazado')->count())->toBe(4);

    // Último cupo: dos tenants canjean el mismo cupón; el bloqueo de fila deja pasar solo uno.
    $last = makeCoupon(['code' => 'ULTIMO1', 'max_redemptions' => 1]);
    $other = $this->makeTenant('inka');
    $subs = plat()->table('subscriptions')->whereIn('tenant_id', [$this->tenant->id, $other->id])->get()->keyBy('tenant_id');
    plat()->table('subscriptions')->where('id', $subs[$this->tenant->id]->id)->update(['coupon_redemption_id' => null]);
    $results = [];
    foreach ([$this->tenant->id, $other->id] as $tenant) {
        try {
            app(CouponService::class)->redeem(plat()->table('subscriptions')->where('tenant_id', $tenant)->first(), 'ULTIMO1', null);
            $results[] = 'ok';
        } catch (ApiException $e) {
            $results[] = $e->error;
        }
    }
    expect($results)->toBe(['ok', 'COUPON_INVALID'])->and((int) plat()->table('coupons')->where('id', $last->id)->value('redeemed_count'))->toBe(1);
});

it('§10-6 entitlements: override > add-on > plan; override vencido → rige el plan; ecommerce en Professional → 403', function () {
    setPlan($this, 'professional');
    $this->as('admin')->getJson('/api/v1/channels/ecommerce/orders')->assertForbidden()->assertJsonPath('error', 'FEATURE_NOT_IN_PLAN');

    $ent = app(EntitlementService::class);
    expect($ent->for($this->tenant->id)['b2b']['origin'])->toBe('plan');
    plat()->table('subscription_items')->insert(['id' => (string) Str::uuid7(), 'subscription_id' => ($this->sub)()->id, 'addon_code' => 'b2b_full', 'quantity' => 1,
        'unit_price' => 390, 'starts_at' => now()->toDateString(), 'created_at' => now()]);
    EntitlementService::forget($this->tenant->id);
    expect($ent->for($this->tenant->id)['b2b'])->toMatchArray(['origin' => 'addon', 'value' => 'completo']);

    $this->api($this->saToken)->postJson('/api/v1/platform/feature-overrides', ['tenant_id' => $this->tenant->id, 'feature_key' => 'b2b', 'value' => false,
        'reason' => 'Prueba de precedencia'])->assertCreated();
    expect($ent->for($this->tenant->id)['b2b'])->toMatchArray(['origin' => 'override', 'enabled' => false]);

    $this->api($this->saToken)->postJson('/api/v1/platform/feature-overrides', ['tenant_id' => $this->tenant->id, 'feature_key' => 'ecommerce', 'value' => true,
        'reason' => 'Piloto'])->assertCreated();
    $this->as('admin')->getJson('/api/v1/channels/ecommerce/orders')->assertOk();
    plat()->table('feature_overrides')->where('tenant_id', $this->tenant->id)->where('feature_key', 'ecommerce')->update(['valid_until' => now()->subDay()->toDateString()]);
    EntitlementService::forget($this->tenant->id);
    $this->as('admin')->getJson('/api/v1/channels/ecommerce/orders')->assertForbidden()->assertJsonPath('error', 'FEATURE_NOT_IN_PLAN');
});

it('§10-7 límites: usuario 6 en Starter → 422; API al 100 % → 429 QUOTA_EXCEEDED; rate por minuto → 429 con Retry-After', function () {
    setPlan($this, 'starter');
    $sede = plat()->table('iam_sedes')->where('tenant_id', $this->tenant->id)->value('id');
    expect(app(LimitService::class)->gauges($this->tenant->id)['users'])->toBe(7);
    plat()->table('iam_users')->where('tenant_id', $this->tenant->id)->whereIn('code', ['OPS', 'ACCOUNTING'])->update(['status' => 'inactivo']);
    $this->as('admin')->withHeaders($this->idem())->postJson('/api/v1/iam/users', ['name' => 'Seis', 'email' => 'seis@andes.test', 'role' => 'sales', 'sede_id' => $sede])
        ->assertUnprocessable()->assertJsonPath('error', 'PLAN_LIMIT_REACHED')->assertJsonPath('code', 'USR-BR-02');

    setPlan($this, 'professional');
    $token = apiKey($this)['token'];
    ext($this, $token)->assertOk()->assertHeader('X-RateLimit-Limit', '60')->assertHeader('X-Quota-Limit', '100000');
    plat()->table('usage_counters')->insert(['tenant_id' => $this->tenant->id, 'metric' => 'api_calls_month', 'day' => now('America/Lima')->toDateString(), 'value' => 100000]);
    UsageMeter::forgetBase($this->tenant->id, 'api_calls_month');
    ext($this, $token)->assertStatus(429)->assertJsonPath('error', 'QUOTA_EXCEEDED')->assertHeader('Retry-After');

    plat()->table('usage_counters')->where('tenant_id', $this->tenant->id)->delete();
    UsageMeter::forgetBase($this->tenant->id, 'api_calls_month');
    $key = plat()->table('integration_api_keys')->where('tenant_id', $this->tenant->id)->first();
    for ($i = 0; $i < 60; $i++) {
        RateLimiter::hit("rl:{$this->tenant->id}:{$key->id}", 60);
    }
    $res = ext($this, $token)->assertStatus(429)->assertJsonPath('error', 'RATE_LIMITED');
    expect((int) $res->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('§10-8 API keys: token solo en el 201, en BD solo el hash; revocada → 401; sin scope o fuera de la IP → 403; aislamiento entre tenants', function () {
    $key = apiKey($this);
    expect($key['token'])->toStartWith('qts_live_')->and(strlen($key['token']))->toBe(41);
    $row = plat()->table('integration_api_keys')->where('id', $key['id'])->first();
    expect($row->token_hash)->toBe(hash('sha256', $key['token']))->and(json_encode($row))->not->toContain($key['token']);
    expect(collect($this->as('admin')->getJson('/api/v1/integrations/api-keys')->assertOk()->json('data'))->firstWhere('id', $key['id']))->not->toHaveKey('token');

    ext($this, $key['token'], 'leads')->assertStatus(405);
    $this->withHeaders(['Authorization' => "Bearer {$key['token']}"])->postJson('/api/v1/ext/leads', ['full_name' => 'X'])
        ->assertForbidden()->assertJsonPath('error', 'INSUFFICIENT_SCOPE');
    $ipLocked = apiKey($this, ['name' => 'Oficina', 'ip_allowlist' => ['10.10.0.0/16']]);
    ext($this, $ipLocked['token'])->assertForbidden()->assertJsonPath('error', 'IP_NOT_ALLOWED');

    // Key del tenant A no lee reservas de B.
    $other = $this->makeTenant('inka');
    $this->tenant = $other;
    $this->tid = $other->id;
    $this->users['admin'] = $this->makeUser($other, 'tenant_admin');
    $this->tokens['admin'] = $this->tokenFor($this->users['admin']);
    $keyB = apiKey($this);
    expect(ext($this, $keyB['token'])->assertOk()->json('data'))->toBe([]);
    ext($this, $keyB['token'], 'reservations/RES-000001')->assertNotFound();

    $this->as('admin')->deleteJson("/api/v1/integrations/api-keys/{$keyB['id']}", ['reason' => 'Rotación'])->assertOk();
    ext($this, $keyB['token'])->assertUnauthorized()->assertJsonPath('error', 'API_KEY_INVALID');
    ext($this, 'qts_live_inexistente')->assertUnauthorized();
});

it('§10-9 webhooks: http o red privada → 422; firma verificable; rotación con ambas firmas 24 h; 10 fallos → disabled; reenvío manual', function () {
    $create = fn (string $url) => $this->as('admin')->postJson('/api/v1/integrations/webhooks', ['url' => $url, 'events' => ['lead.created']]);
    $create('http://webhook.test/erp')->assertUnprocessable();
    $create('https://10.0.0.5/hook')->assertUnprocessable();
    $create('https://localhost/hook')->assertUnprocessable();
    $hook = $create('https://webhook.test/erp')->assertCreated()->json('data');
    expect($hook['secret'])->toStartWith('whsec_');

    $ping = $this->as('admin')->postJson("/api/v1/integrations/webhooks/{$hook['id']}/ping")->assertOk()->json('data');
    $detail = $this->as('admin')->getJson("/api/v1/integrations/webhook-deliveries/{$ping['id']}")->assertOk()->json('data');
    $body = json_encode($detail['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    expect(WebhookSigner::verify($hook['secret'], $detail['request_headers']['Qhapaq-Signature'], $body))->toBeTrue()
        ->and(WebhookSigner::verify('whsec_otro', $detail['request_headers']['Qhapaq-Signature'], $body))->toBeFalse();

    $rotated = $this->as('admin')->postJson("/api/v1/integrations/webhooks/{$hook['id']}/rotate-secret")->assertOk()->json('data');
    $ping = $this->as('admin')->postJson("/api/v1/integrations/webhooks/{$hook['id']}/ping")->assertOk()->json('data');
    $detail = $this->as('admin')->getJson("/api/v1/integrations/webhook-deliveries/{$ping['id']}")->json('data');
    $sig = $detail['request_headers']['Qhapaq-Signature'];
    $body = json_encode($detail['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    expect(substr_count($sig, 'v1='))->toBe(2)->and(WebhookSigner::verify($rotated['secret'], $sig, $body))->toBeTrue()
        ->and(WebhookSigner::verify($hook['secret'], $sig, $body))->toBeTrue();
    // Pasadas las 24 h de gracia solo firma el secreto vigente.
    plat()->table('integration_webhooks')->where('id', $hook['id'])->update(['previous_secret_expires_at' => now()->subMinute()]);
    $ping = $this->as('admin')->postJson("/api/v1/integrations/webhooks/{$hook['id']}/ping")->json('data');
    expect(substr_count($this->as('admin')->getJson("/api/v1/integrations/webhook-deliveries/{$ping['id']}")->json('data.request_headers.Qhapaq-Signature'), 'v1='))->toBe(1);

    // Receptor caído: 10 fallos consecutivos deshabilitan el webhook; el reenvío crea una entrega manual.
    $failing = $create('https://webhook.test/erp/fail')->assertCreated()->json('data');
    $this->inTenant(fn () => app(WebhookService::class)->dispatch('lead.created', 'LEAD-1', ['code' => 'LEAD-1']));
    $first = plat()->table('integration_webhook_deliveries')->where('webhook_id', $failing['id'])->orderBy('created_at')->first();
    for ($i = 0; $i < 9; $i++) {
        $this->as('admin')->withHeaders($this->idem())->postJson("/api/v1/integrations/webhook-deliveries/{$first->id}/redeliver")->assertOk();
    }
    $w = plat()->table('integration_webhooks')->where('id', $failing['id'])->first();
    expect($w->status)->toBe('disabled')->and((int) $w->consecutive_failures)->toBeGreaterThanOrEqual(10)
        ->and(plat()->table('integration_webhook_deliveries')->where('webhook_id', $failing['id'])->where('manual', true)->count())->toBe(9)
        ->and(plat()->table('notif_messages')->where('tenant_id', $this->tenant->id)->where('event', 'webhook.deshabilitado')->exists())->toBeTrue();
});

it('§10-10 RBAC: Manager 403 en billing e integraciones; Tenant Admin 403 en plataforma; Super Admin 403 en negocio', function () {
    $this->as('manager')->getJson('/api/v1/billing/subscription')->assertForbidden();
    $this->as('manager')->getJson('/api/v1/integrations/public-api')->assertForbidden();
    $this->as('manager')->getJson('/api/v1/billing/entitlements')->assertOk();
    $this->as('admin')->getJson('/api/v1/billing/subscription')->assertOk();
    $this->as('admin')->getJson('/api/v1/platform/metrics')->assertForbidden();
    $this->as('admin')->getJson('/api/v1/platform/coupons')->assertForbidden();
    $this->api($this->saToken)->getJson('/api/v1/platform/metrics')->assertOk();
    $this->api($this->saToken)->getJson('/api/v1/billing/subscription')->assertForbidden();
    $this->api($this->saToken)->getJson('/api/v1/crm/leads')->assertForbidden();
    $this->api($this->saToken)->getJson('/api/v1/integrations/api-keys')->assertForbidden();
});

it('§10-11 usage:consolidate: UPSERT idempotente y aviso del 80 % una sola vez por mes', function () {
    $day = now('America/Lima')->startOfDay();
    for ($i = 0; $i < 4100; $i += 100) {
        app(UsageMeter::class)->increment($this->tenant->id, 'whatsapp_month', 100);
    }
    $this->artisan('usage:consolidate')->assertSuccessful();
    $this->artisan('usage:consolidate')->assertSuccessful();
    expect((int) plat()->table('usage_counters')->where(['tenant_id' => $this->tenant->id, 'metric' => 'whatsapp_month', 'day' => $day->toDateString()])->value('value'))->toBe(4100)
        ->and(plat()->table('usage_counters')->where(['tenant_id' => $this->tenant->id, 'metric' => 'whatsapp_month'])->count())->toBe(1);
    $month = plat()->table('usage_monthly')->where(['tenant_id' => $this->tenant->id, 'metric' => 'whatsapp_month'])->get();
    expect($month)->toHaveCount(1)->and((int) $month[0]->value)->toBe(4100)->and($month[0]->alert_80_sent_at)->not->toBeNull();
    expect(plat()->table('notif_messages')->where('tenant_id', $this->tenant->id)->where('event', 'uso.aviso_80')->where('channel', 'email')->count())->toBe(1);

    app(MetricsService::class)->consolidate();
    expect(plat()->table('notif_messages')->where('tenant_id', $this->tenant->id)->where('event', 'uso.aviso_80')->where('channel', 'email')->count())->toBe(1);
});

it('§10-12 SUNAT: con el OSE caído la factura queda pending, el job reintenta y el cobro no se bloquea', function () {
    config(['turismo.platform.ose_down' => true]);
    Queue::fake();
    addCard($this);
    $service = app(PlatformInvoiceService::class);
    $invoice = $service->issue(($this->sub)(), 'subscription', [['Enterprise mensual', 1, '2890.00', 'plan']], now()->toDateString(), now()->addMonth()->toDateString());
    expect($service->find($invoice->id)->sunat_status)->toBe('pending')->and($service->find($invoice->id)->sunat_error)->toContain('OSE');
    Queue::assertPushed(EmitPlatformInvoiceJob::class);
    expect((new EmitPlatformInvoiceJob($invoice->id))->tries)->toBe(5);

    $charge = $service->charge($service->find($invoice->id), 'initial', "inv:{$invoice->id}:attempt:1");
    expect($charge['attempt']->result)->toBe('succeeded')->and($charge['invoice']->status)->toBe('paid')->and($charge['invoice']->sunat_status)->toBe('pending');

    config(['turismo.platform.ose_down' => false]);
    (new EmitPlatformInvoiceJob($invoice->id))->handle($service);
    expect($service->find($invoice->id)->sunat_status)->toBe('accepted');
});

it('SUB-BR-08 y SUB-BR-06: cupón de más de 50 % → 422; el If-Match desfasado → 409', function () {
    $this->api($this->saToken)->postJson('/api/v1/platform/coupons', ['code' => 'VERANO27', 'type' => 'percent', 'value' => 60, 'duration_cycles' => 2, 'max_redemptions' => 50,
        'valid_to' => now()->addMonths(3)->toDateString()])->assertUnprocessable()->assertJsonPath('code', 'SUB-BR-08');
    $this->api($this->saToken)->postJson('/api/v1/platform/coupons', ['code' => 'VERANO27', 'type' => 'percent', 'value' => 25, 'duration_cycles' => 2, 'max_redemptions' => 50,
        'valid_to' => now()->addMonths(3)->toDateString()])->assertCreated();

    $this->as('admin')->postJson('/api/v1/billing/subscription/cancel', ['reason' => 'price', 'accept' => true])->assertStatus(428);
    $this->as('admin')->withHeaders(['If-Match' => '99'])->postJson('/api/v1/billing/subscription/cancel', ['reason' => 'price', 'accept' => true])->assertStatus(409);
    expect(PlatformCatalog::SCOPES)->toHaveKey('leads:write');
});
