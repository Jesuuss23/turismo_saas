<?php

use App\Models\Iam\User;
use App\Models\Sys\NotifMessage;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->tenant = $this->makeTenant('andes');
    $this->user = $this->makeUser($this->tenant, 'sales', ['email' => 'Carla@Andes.test']);
});

function login(string $email, string $password = 'Secreta123!', string $slug = 'andes')
{
    return test()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password, 'tenant_slug' => $slug]);
}

it('inicia sesión (correo sin distinguir mayúsculas), audita y devuelve el perfil', function () {
    $response = login('carla@andes.test')->assertOk()
        ->assertJsonPath('mfa_required', false)
        ->assertJsonPath('user.role.name', 'sales')
        ->assertJsonPath('user.scope_label', 'Mi cartera')
        ->assertJsonPath('user.home', 'dashboard');

    $this->api($response->json('token'))->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', 'Carla@Andes.test');

    $event = $this->admin()->table('audit_events')->where('action', 'login')->first();
    expect($event->after_value)->toBe('OK')->and($event->is_critical)->toBeTrue()->and($event->actor_role)->toBe('sales');
});

it('rechaza credenciales inválidas con 401 y audita login_fallido', function () {
    login('carla@andes.test', 'mala')->assertUnauthorized()->assertJsonPath('error', 'INVALID_CREDENTIALS');
    login('carla@andes.test', 'Secreta123!', 'inka')->assertUnauthorized();

    expect($this->admin()->table('audit_events')->where('action', 'login_fallido')->count())->toBe(2);
});

it('SEC-BR-25: limita los intentos de login a 5 por minuto', function () {
    foreach (range(1, 5) as $i) {
        login('carla@andes.test', 'mala')->assertUnauthorized();
    }
    login('carla@andes.test', 'mala')->assertStatus(429)->assertJsonPath('code', 'SEC-BR-25');
});

it('logout revoca el token', function () {
    $token = login('carla@andes.test')->json('token');

    $this->api($token)->postJson('/api/v1/auth/logout')->assertOk();
    $this->api($token)->getJson('/api/v1/me')->assertUnauthorized();
    expect($this->admin()->table('audit_events')->where('action', 'logout')->count())->toBe(1);
});

it('X-11 y SEC-BR-08: flujo MFA completo con enrolamiento, verificación y anti-replay', function () {
    $this->admin()->table('tenants')->where('id', $this->tenant->id)->update(['settings' => json_encode(['security' => ['mfa_internal_required' => true]])]);
    $totp = new Google2FA;

    // 1) Login → token limitado al paso MFA; no accede a recursos.
    $pending = login('carla@andes.test')->assertOk()
        ->assertJsonPath('mfa_required', true)
        ->assertJsonPath('mfa_status', 'enroll')
        ->json('token');
    $this->api($pending)->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('error', 'MFA_REQUIRED');

    // 2) Enrolar y confirmar → token completo + 8 códigos de recuperación.
    $secret = $this->api($pending)->postJson('/api/v1/me/mfa/enroll')->assertOk()->json('secret');
    $confirm = $this->api($pending)->postJson('/api/v1/me/mfa/confirm', ['code' => $totp->getCurrentOtp($secret)])->assertOk();
    expect($confirm->json('recovery_codes'))->toHaveCount(8);
    $this->api($confirm->json('token'))->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.mfa.enabled', true);
    $this->api($pending)->getJson('/api/v1/me')->assertUnauthorized();

    // 3) Nuevo login → verify. El mismo código ya usado no sirve (anti-replay).
    $pending2 = login('carla@andes.test')->assertJsonPath('mfa_status', 'verify')->json('token');
    $this->api($pending2)->postJson('/api/v1/auth/mfa/verify', ['code' => $totp->getCurrentOtp($secret)])
        ->assertStatus(422)->assertJsonPath('error', 'MFA_INVALID_CODE');

    // 4) Código de recuperación: funciona una sola vez.
    $recovery = $confirm->json('recovery_codes.0');
    $full = $this->api($pending2)->postJson('/api/v1/auth/mfa/verify', ['code' => $recovery])->assertOk()->json('token');
    $this->api($full)->getJson('/api/v1/me')->assertOk();

    $pending3 = login('carla@andes.test')->json('token');
    $this->api($pending3)->postJson('/api/v1/auth/mfa/verify', ['code' => $recovery])->assertStatus(422);

    expect($this->admin()->table('audit_events')->where('action', 'login')->where('after_value', 'MFA OK')->count())->toBe(2);
});

it('los externos no requieren MFA por la política interna', function () {
    $this->admin()->table('tenants')->where('id', $this->tenant->id)->update(['settings' => json_encode(['security' => ['mfa_internal_required' => true]])]);
    $customer = $this->makeUser($this->tenant, 'customer', ['client_id' => $this->clientRow($this->tenant)]);

    login($customer->email)->assertOk()->assertJsonPath('mfa_required', false)->assertJsonPath('user.experience', 'portal');
});

it('restablece la contraseña con enlace de un solo uso y revoca sesiones', function () {
    Mail::fake();
    $session = login('carla@andes.test')->json('token');

    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'carla@andes.test', 'tenant_slug' => 'andes'])->assertStatus(202);
    $body = app(TenantContext::class)->run($this->tenant, fn () => NotifMessage::where('event', 'auth.password_reset')->value('body'));
    preg_match('/token=([A-Za-z0-9]+)/', $body, $m);

    $payload = ['email' => 'carla@andes.test', 'tenant_slug' => 'andes', 'token' => $m[1], 'password' => 'NuevaClave2026', 'password_confirmation' => 'NuevaClave2026'];
    $this->postJson('/api/v1/auth/password/reset', $payload)->assertOk();
    $this->postJson('/api/v1/auth/password/reset', $payload)->assertStatus(422);

    $this->api($session)->getJson('/api/v1/me')->assertUnauthorized();
    login('carla@andes.test', 'NuevaClave2026')->assertOk();
});

it('usuario pendiente o inactivo no puede iniciar sesión', function () {
    app(TenantContext::class)->run($this->tenant, fn () => User::find($this->user->id)->forceFill(['status' => User::PENDING])->save());

    login('carla@andes.test')->assertUnauthorized();
});
