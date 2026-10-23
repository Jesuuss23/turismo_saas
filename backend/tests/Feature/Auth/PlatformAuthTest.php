<?php

use PragmaRX\Google2FA\Google2FA;

it('SEC-BR-06: el Super Admin entra siempre con MFA y solo a su consola', function () {
    createPlatformUser('sa@turismo.test', 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP');

    $pending = $this->postJson('/api/v1/platform/auth/login', ['email' => 'sa@turismo.test', 'password' => 'Secreta123!'])
        ->assertOk()->assertJsonPath('mfa_required', true)->json('token');
    $this->api($pending)->getJson('/api/v1/platform/me')->assertForbidden()->assertJsonPath('error', 'MFA_REQUIRED');

    $code = (new Google2FA)->getCurrentOtp('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP');
    $token = $this->api($pending)->postJson('/api/v1/platform/auth/mfa/verify', ['code' => $code])->assertOk()->json('token');

    $this->api($token)->getJson('/api/v1/platform/me')->assertOk()
        ->assertJsonPath('home', 'admin/tenants')
        ->assertJsonPath('permissions', ['admin.tenant.manage', 'admin.audit.view']);

    $event = $this->admin()->table('audit_events')->where('action', 'login')->first();
    expect($event->tenant_id)->toBeNull()->and($event->actor_type)->toBe('platform_user');
});

it('un usuario de tenant no entra a la consola de plataforma', function () {
    $tenant = $this->makeTenant();
    $token = $this->tokenFor($this->makeUser($tenant, 'tenant_admin'));

    $this->api($token)->getJson('/api/v1/platform/me')->assertForbidden()->assertJsonPath('code', 'SEC-BR-06');
});
