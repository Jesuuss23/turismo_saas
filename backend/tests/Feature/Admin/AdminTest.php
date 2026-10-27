<?php

use App\Models\Admin\IntegrationError;
use App\Models\Iam\PlatformUser;
use App\Models\Iam\User;
use App\Services\Auth\TokenIssuer;
use App\Services\Notify;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/*
| Spec 09 §10: casos mínimos de Administración, reportes y panel ejecutivo (T-03…T-28).
| T-01, T-02, T-06 y T-27 están cubiertos en Security/TenantIsolationTest y Core/AuditTest.
*/

function platformToken(PlatformUser $user): string
{
    return app(TokenIssuer::class)->issue($user, false, null, '127.0.0.1')->plainTextToken;
}

function wizardPayload(array $overrides = []): array
{
    return $overrides + [
        'trade_name' => 'Cóndor Tours', 'slug' => 'condor', 'country' => 'Perú', 'logo_initials' => 'CT',
        'legal_name' => 'Cóndor Tours S.A.C.', 'ruc' => '20512345678', 'fiscal_address' => 'Av. Sol 120, Cusco', 'business_type' => 'Operador turístico',
        'plan_id' => 'professional', 'sede_name' => 'Sede Cusco', 'sede_city' => 'Cusco', 'sede_address' => 'Av. Sol 120',
        'admin_name' => 'Rosa Quispe', 'admin_email' => 'rosa@condor.test', 'roles' => ['manager', 'sales', 'operations'],
        'permission_template' => 'estandar', 'channels' => ['portal_cliente', 'b2b'], 'quote_validity_days' => 10, 'hold_minutes' => 30,
        'max_discount_pct' => 5, 'currency' => 'PEN', 'alt_currency' => 'USD', 'igv_pct' => 18, 'fx_rate' => 3.75, 'timezone' => 'America/Lima',
        'locale' => 'es-PE', 'status' => 'prueba',
    ];
}

beforeEach(function () {
    $this->sa = createPlatformUser();
    $this->saToken = platformToken($this->sa);
    $this->platform = fn () => $this->api($this->saToken);
});

// ───────────────────────────── Plataforma (PROC-001) ─────────────────────────────

it('T-03: alta de tenant valida RUC, subdominio y duplicados (TEN-BR-01/02)', function () {
    $this->crmWorld();
    ($this->platform)()->postJson('/api/v1/platform/tenants/validate-step', ['step' => 2, 'legal_name' => 'X', 'ruc' => '30123456789', 'fiscal_address' => 'x', 'business_type' => 'Agencia mayorista'])
        ->assertUnprocessable()->assertJsonPath('errors.ruc.0', 'RUC inválido: 11 dígitos que inician con 10, 15, 17 o 20.');
    ($this->platform)()->postJson('/api/v1/platform/tenants/validate-step', ['step' => 1, 'trade_name' => 'X', 'slug' => 'AB', 'country' => 'Perú'])
        ->assertUnprocessable()->assertJsonPath('errors.slug.0', 'Subdominio inválido: use 3 a 30 caracteres en minúsculas, números o guiones.');
    ($this->platform)()->postJson('/api/v1/platform/tenants/validate-step', ['step' => 1, 'trade_name' => 'X', 'slug' => 'ANDES', 'country' => 'Perú'])
        ->assertUnprocessable()->assertJsonPath('errors.slug.0', 'El subdominio ya está en uso por otro tenant.');
    ($this->platform)()->postJson('/api/v1/platform/tenants/validate-step', ['step' => 9, 'quote_validity_days' => 10, 'hold_minutes' => 30, 'max_discount_pct' => 35])
        ->assertUnprocessable()->assertJsonValidationErrors('max_discount_pct');
    ($this->platform)()->postJson('/api/v1/platform/tenants/validate-step', ['step' => 8, 'plan_id' => 'starter', 'channels' => ['ecommerce']])
        ->assertUnprocessable()->assertJsonPath('code', 'TEN-BR-06');
    ($this->platform)()->postJson('/api/v1/platform/tenants/validate-step', ['step' => 1, 'trade_name' => 'X', 'slug' => 'nuevo-tenant', 'country' => 'Perú'])
        ->assertOk()->assertJsonPath('valid', true);
});

it('T-04: alta completa idempotente crea tenant, sede, administrador invitado, roles y configuración', function () {
    $this->crmWorld();
    $headers = ['Idempotency-Key' => 'alta-condor-1'];
    $first = ($this->platform)()->withHeaders($headers)->postJson('/api/v1/platform/tenants', wizardPayload())->assertCreated();
    $second = ($this->platform)()->withHeaders($headers)->postJson('/api/v1/platform/tenants', wizardPayload())->assertCreated();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and($this->admin()->table('tenants')->where('slug', 'condor')->count())->toBe(1)
        ->and($first->json('data.status'))->toBe('prueba')->and($first->json('data.code'))->toBe('TNT-0002');
    $id = $first->json('data.id');
    expect($this->admin()->table('iam_users')->where('tenant_id', $id)->where('email', 'rosa@condor.test')->value('status'))->toBe('pendiente')
        ->and($this->admin()->table('roles')->where('tenant_id', $id)->pluck('name')->sort()->values()->all())->toBe(['manager', 'operations', 'sales', 'tenant_admin'])
        ->and(json_decode($this->admin()->table('sys_settings')->where('tenant_id', $id)->where('section', 'channels')->value('values'), true))
        ->toMatchArray(['portal_cliente' => true, 'b2b' => true, 'whatsapp' => false])
        ->and($this->admin()->table('tenant_onboarding')->where('tenant_id', $id)->value('permission_template'))->toBe('estandar')
        ->and($this->admin()->table('audit_events')->where('entity', 'Tenant')->where('action', 'alta')->count())->toBe(1)
        ->and($this->admin()->table('iam_user_invitations')->where('tenant_id', $id)->count())->toBe(1);
});

it('T-05: no se suspende el tenant de la sesión activa (TEN-BR-07) y suspender/reactivar revoca y restaura', function () {
    $this->crmWorld();
    $this->sa->forceFill(['home_tenant_id' => $this->tenant->id])->save();
    $lock = ['If-Match' => '0'];
    ($this->platform)()->withHeaders($lock)->postJson("/api/v1/platform/tenants/{$this->tenant->id}/suspend", ['reason' => 'Prueba'])
        ->assertStatus(409)->assertJsonPath('error', 'TEN-BR-07');

    $this->sa->forceFill(['home_tenant_id' => null])->save();
    ($this->platform)()->withHeaders($lock)->postJson("/api/v1/platform/tenants/{$this->tenant->id}/suspend", [])->assertUnprocessable();
    ($this->platform)()->withHeaders($lock)->postJson("/api/v1/platform/tenants/{$this->tenant->id}/suspend", ['reason' => 'Falta de pago'])
        ->assertOk()->assertJsonPath('data.status', 'suspendido');

    // T-06 / TEN-BR-10: sus usuarios quedan fuera (tokens revocados).
    $this->as('admin')->getJson('/api/v1/me')->assertUnauthorized();

    ($this->platform)()->withHeaders(['If-Match' => '1'])->postJson("/api/v1/platform/tenants/{$this->tenant->id}/reactivate", ['reason' => 'Pago recibido'])
        ->assertOk()->assertJsonPath('data.status', 'activo');
    expect($this->admin()->table('audit_events')->where('entity', 'Tenant')->whereIn('action', ['suspender', 'reactivar'])->count())->toBe(2);
});

it('T-07: downgrade a Starter con 9 usuarios → 409 TEN-BR-09; mismo plan → aviso sin cambios', function () {
    $this->crmWorld();
    $this->admin()->table('tenants')->where('id', $this->tenant->id)->update(['plan_id' => 'professional']);
    $this->makeUser($this->tenant, 'sales');
    $this->makeUser($this->tenant, 'sales');
    ($this->platform)()->withHeaders(['If-Match' => '0'])->postJson("/api/v1/platform/tenants/{$this->tenant->id}/change-plan", ['plan_id' => 'starter'])
        ->assertStatus(409)->assertJsonPath('error', 'PLAN_LIMIT_EXCEEDED');
    ($this->platform)()->withHeaders(['If-Match' => '0'])->postJson("/api/v1/platform/tenants/{$this->tenant->id}/change-plan", ['plan_id' => 'professional'])
        ->assertOk()->assertJsonPath('data.warnings.0', 'El tenant ya está en el plan Professional: sin cambios.');
    ($this->platform)()->withHeaders(['If-Match' => '0'])->postJson("/api/v1/platform/tenants/{$this->tenant->id}/change-plan", ['plan_id' => 'enterprise'])
        ->assertOk()->assertJsonPath('data.plan', 'Enterprise');
});

it('T-26: el Super Admin solo ve eventos de plataforma y no entra a datos de negocio', function () {
    $this->crmWorld();
    $this->makeLead('u03');
    $events = ($this->platform)()->getJson('/api/v1/platform/audit-events?period=30')->assertOk()->json('data');
    expect(collect($events)->pluck('entity')->intersect(['Lead', 'Cliente', 'Cotización'])->all())->toBe([]);

    // T-14 (parte): el token de plataforma no sirve en endpoints del tenant.
    ($this->platform)()->getJson('/api/v1/crm/leads')->assertForbidden();
    ($this->platform)()->getJson('/api/v1/platform/me/access/check?path=crm/leads')->assertOk()->assertJsonPath('allowed', false);
    ($this->platform)()->getJson('/api/v1/platform/me/access/check?path=admin/tenants')->assertOk()->assertJsonPath('allowed', true);
});

it('E2E-08 desde consola: la prueba de aislamiento pasa y queda auditada', function () {
    $this->crmWorld();
    $other = $this->makeTenant('inka');
    $result = ($this->platform)()->postJson('/api/v1/platform/tenants/isolation-test', ['tenant_a' => $this->tenant->id, 'tenant_b' => $other->id])->assertOk();
    expect($result->json('data.passed'))->toBeTrue()
        ->and($this->admin()->table('audit_events')->where('action', 'acceso_denegado')->where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

// ───────────────────────────── Usuarios y sedes (PROC-002) ─────────────────────────────

it('T-08: invitar con plan lleno → 422 USR-BR-02 (spec 13); correo duplicado en mayúsculas → 422 USR-BR-01', function () {
    $this->crmWorld();
    $sede = $this->admin()->table('iam_sedes')->where('tenant_id', $this->tenant->id)->value('id');
    $this->as('admin')->withHeaders($this->idem())->postJson('/api/v1/iam/users', ['name' => 'Otro', 'email' => strtoupper($this->users['u03']->email), 'role' => 'sales', 'sede_id' => $sede])
        ->assertUnprocessable()->assertJsonPath('code', 'USR-BR-01');

    $invite = $this->as('admin')->withHeaders($this->idem())->postJson('/api/v1/iam/users', ['name' => 'Nuevo', 'email' => 'nuevo@andes.test', 'role' => 'sales', 'sede_id' => $sede])
        ->assertCreated();
    expect($invite->json('user.status'))->toBe('pendiente')->and($invite->json('invitation_url'))->toContain('/invitacion/');

    $this->admin()->table('tenants')->where('id', $this->tenant->id)->update(['plan_id' => 'starter']);
    $this->as('admin')->withHeaders($this->idem())->postJson('/api/v1/iam/users', ['name' => 'Tope', 'email' => 'tope@andes.test', 'role' => 'sales', 'sede_id' => $sede])
        ->assertUnprocessable()->assertJsonPath('error', 'PLAN_LIMIT_REACHED')->assertJsonPath('code', 'USR-BR-02');
});

it('la invitación se acepta una sola vez y activa al usuario', function () {
    $this->crmWorld();
    $sede = $this->admin()->table('iam_sedes')->where('tenant_id', $this->tenant->id)->value('id');
    $url = $this->as('admin')->withHeaders($this->idem())->postJson('/api/v1/iam/users', ['name' => 'Nuevo', 'email' => 'nuevo@andes.test', 'role' => 'sales', 'sede_id' => $sede])
        ->json('invitation_url');
    $token = basename($url);
    app(TenantContext::class)->clear();

    $this->getJson("/api/v1/auth/invitations/{$token}")->assertOk()->assertJsonPath('data.email', 'nuevo@andes.test');
    $this->postJson("/api/v1/auth/invitations/{$token}/accept", ['password' => 'Turismo2026!', 'password_confirmation' => 'Turismo2026!'])->assertOk();
    $this->postJson("/api/v1/auth/invitations/{$token}/accept", ['password' => 'Turismo2026!', 'password_confirmation' => 'Turismo2026!'])->assertStatus(410);
    expect($this->admin()->table('iam_users')->where('email', 'nuevo@andes.test')->value('status'))->toBe('activo');
});

it('T-09: el TA no cambia su rol ni se desactiva; se conserva un TA activo', function () {
    $this->crmWorld();
    $admin = $this->users['admin'];
    $this->as('admin')->withHeaders(['If-Match' => (string) $admin->lock_version])->patchJson("/api/v1/iam/users/{$admin->code}", ['role' => 'manager'])
        ->assertForbidden()->assertJsonPath('code', 'USR-BR-04');
    $this->as('admin')->postJson("/api/v1/iam/users/{$admin->code}/deactivate", ['reason' => 'x'])->assertForbidden()->assertJsonPath('code', 'USR-BR-06');

    $other = $this->makeUser($this->tenant, 'tenant_admin', ['code' => 'TA2']);
    $this->api($this->tokenFor($other))->postJson("/api/v1/iam/users/{$admin->code}/deactivate", ['reason' => 'Rotación'])->assertOk()
        ->assertJsonPath('user.status', 'inactivo');
});

it('T-10: desactivar un usuario revoca sus tokens', function () {
    $this->crmWorld();
    $this->as('u04')->getJson('/api/v1/me')->assertOk();
    $this->as('admin')->postJson('/api/v1/iam/users/U04/deactivate', ['reason' => 'Fin de contrato'])->assertOk()->assertJsonPath('user.status', 'inactivo');
    $this->as('u04')->getJson('/api/v1/me')->assertUnauthorized();
});

it('T-11: sedes sobre el límite → 422 SED-BR-02 (spec 13); con usuarios o con reservas → 409 SED-BR-03/04', function () {
    $this->crmWorld();
    $this->admin()->table('tenants')->where('id', $this->tenant->id)->update(['plan_id' => 'starter']);
    $this->as('admin')->postJson('/api/v1/iam/sedes', ['name' => 'Arequipa', 'city' => 'Arequipa', 'address' => 'Calle Mercaderes 1'])
        ->assertUnprocessable()->assertJsonPath('error', 'PLAN_LIMIT_REACHED')->assertJsonPath('code', 'SED-BR-02');

    $this->admin()->table('tenants')->where('id', $this->tenant->id)->update(['plan_id' => 'professional']);
    $sede = $this->as('admin')->postJson('/api/v1/iam/sedes', ['name' => 'Arequipa', 'city' => 'Arequipa', 'address' => 'Calle Mercaderes 1'])->assertCreated()->json('data');
    $this->admin()->table('iam_users')->where('id', $this->users['u04']->id)->update(['sede_id' => $sede['id']]);
    $this->as('admin')->postJson("/api/v1/iam/sedes/{$sede['code']}/deactivate")->assertStatus(409)->assertJsonPath('code', 'SED-BR-03');
    $this->as('admin')->deleteJson("/api/v1/iam/sedes/{$sede['code']}")->assertStatus(409)->assertJsonPath('code', 'SED-BR-04');
});

// ───────────────────────────── Roles y permisos ─────────────────────────────

it('T-12: reglas de bloqueo de la matriz (RBAC-BR-02/04/05)', function () {
    $this->crmWorld();
    $this->as('admin')->deleteJson('/api/v1/iam/roles/tenant_admin/permissions/admin.users.manage')->assertUnprocessable()->assertJsonPath('code', 'RBAC-BR-02');
    $this->as('admin')->putJson('/api/v1/iam/roles/b2b/permissions/finance.receivables')->assertUnprocessable()->assertJsonPath('code', 'RBAC-BR-04');
    $this->as('admin')->putJson('/api/v1/iam/roles/manager/permissions/admin.tenant.manage')->assertUnprocessable()->assertJsonPath('code', 'RBAC-BR-05');
});

it('T-13: un permiso otorgado aplica en la siguiente solicitud sin re-login', function () {
    $this->crmWorld();
    $this->as('u03')->getJson('/api/v1/audit-events')->assertForbidden();
    $this->as('admin')->putJson('/api/v1/iam/roles/sales/permissions/admin.audit.view')->assertOk();
    $this->as('u03')->getJson('/api/v1/audit-events')->assertOk();
    expect($this->admin()->table('audit_events')->where('entity', 'Permiso')->where('action', 'otorgar_permiso')->count())->toBe(1);
});

it('T-14: 403 por rol en administración', function () {
    $this->crmWorld();
    $this->as('manager')->patchJson('/api/v1/settings/policies', ['max_discount_pct' => 8])->assertForbidden();
    $this->as('u03')->getJson('/api/v1/iam/users')->assertForbidden();
    $this->as('manager')->getJson('/api/v1/audit-events')->assertOk();
});

// ───────────────────────────── Configuración (PROC-003) ─────────────────────────────

it('T-15: el correlativo no retrocede (CFG-BR-07) y el trigger bloquea el update directo', function () {
    $this->crmWorld();
    $seq = $this->admin()->table('sys_sequences')->where('tenant_id', $this->tenant->id)->where('doc_type', 'quote')->first();
    $start = (int) $seq->next_number;
    $this->as('admin')->patchJson('/api/v1/settings/sequences', ['items' => [['doc_type' => 'quote', 'next_number' => $start + 10]]])->assertOk();
    $this->as('admin')->patchJson('/api/v1/settings/sequences', ['items' => [['doc_type' => 'quote', 'next_number' => $start + 1]]])
        ->assertUnprocessable()->assertJsonPath('code', 'CFG-BR-07');
    expect(fn () => $this->admin()->table('sys_sequences')->where('id', $seq->id)->update(['next_number' => 3]))->toThrow(Exception::class);
});

it('T-17: guardar sin cambios no audita; dos cambios → dos eventos', function () {
    $this->crmWorld();
    $settings = $this->as('admin')->getJson('/api/v1/settings')->assertOk()->json('data.sections.policies');
    $this->as('admin')->patchJson('/api/v1/settings/policies', ['quote_validity_days' => $settings['values']['quote_validity_days']])
        ->assertOk()->assertJsonPath('message', 'No hay cambios que guardar');
    expect($this->admin()->table('audit_events')->where('entity', 'Configuración')->count())->toBe(0);

    $this->as('admin')->withHeaders(['If-Match' => (string) $settings['lock_version']])->patchJson('/api/v1/settings/policies', ['quote_validity_days' => 15, 'hold_minutes' => 45])
        ->assertOk()->assertJsonPath('changed', ['quote_validity_days', 'hold_minutes']);
    expect($this->admin()->table('audit_events')->where('entity', 'Configuración')->count())->toBe(2);
});

// ───────────────────────────── Notificaciones (PROC-059) ─────────────────────────────

it('T-18: la incidencia crítica no queda sin canales (NTF-BR-01)', function () {
    $this->crmWorld();
    $this->as('admin')->patchJson('/api/v1/notifications/rules/incidencia.critica', ['email' => false, 'whatsapp' => false, 'push' => false, 'portal' => false])
        ->assertUnprocessable()->assertJsonPath('code', 'NTF-BR-01');
    $this->as('admin')->patchJson('/api/v1/notifications/rules/incidencia.critica', ['whatsapp' => false])->assertOk()->assertJsonPath('whatsapp', false);
});

it('T-19: plantillas con variables no permitidas o push > 140 → 422', function () {
    $this->crmWorld();
    $this->as('admin')->putJson('/api/v1/notifications/templates/reserva.confirmada/email', ['subject' => 'Hola', 'body' => 'Total {{precio}}'])
        ->assertUnprocessable()->assertJsonPath('code', 'NTF-BR-03');
    $this->as('admin')->putJson('/api/v1/notifications/templates/reserva.confirmada/push', ['subject' => 'Hola', 'body' => str_repeat('a', 141)])
        ->assertUnprocessable()->assertJsonPath('code', 'NTF-BR-04');
    $first = $this->as('admin')->putJson('/api/v1/notifications/templates/reserva.confirmada/email', ['subject' => 'Reserva {{reserva}}', 'body' => 'Hola {{cliente}}'])
        ->assertOk()->json('version');
    $this->as('admin')->putJson('/api/v1/notifications/templates/reserva.confirmada/email', ['subject' => 'Reserva {{reserva}} confirmada', 'body' => 'Hola {{cliente}}'])
        ->assertOk()->assertJsonPath('version', $first + 1);
    $this->as('admin')->putJson('/api/v1/notifications/templates/reserva.confirmada/email', ['subject' => 'Reserva {{reserva}} confirmada', 'body' => 'Hola {{cliente}}'])
        ->assertOk()->assertJsonPath('warnings.0', 'No hay cambios que guardar.');
});

it('T-20: un fallo permanente de WhatsApp no afecta la operación que lo originó (NTF-BR-06)', function () {
    $this->crmWorld();
    config(['turismo.whatsapp_sandbox_numbers' => ['+51900000001']]);
    $this->admin()->table('iam_users')->where('id', $this->users['u03']->id)->update(['phone' => '+51999888777']);
    $lead = $this->makeLead('u03');
    $this->inTenant(fn () => DB::transaction(fn () => app(Notify::class)->push('reserva.confirmada', 'whatsapp', User::find($this->users['u03']->id), 'Reserva confirmada', 'Hola', ['test' => true])));

    $message = $this->admin()->table('notif_messages')->where('channel', 'whatsapp')->first();
    expect($message->status)->toBe('fallido')
        ->and($this->admin()->table('integration_errors')->where('source', 'notification')->value('error_class'))->toBe('permanente')
        ->and($this->fresh('crm_leads', $lead->id)->status)->toBe($lead->status);
    $this->as('admin')->postJson("/api/v1/notifications/messages/{$message->id}/fallback")->assertOk();
    expect($this->admin()->table('notif_messages')->where('is_fallback', true)->where('channel', 'email')->count())->toBe(1);
});

// ───────────────────────────── Integraciones y jobs ─────────────────────────────

it('T-21: una integración por validar no pasa a Producción (INT-BR-02)', function () {
    $this->crmWorld();
    $code = $this->admin()->table('integration_adapters')->where('tenant_id', $this->tenant->id)->where('status', 'por_validar')->value('code');
    $this->as('admin')->patchJson("/api/v1/integrations/{$code}/config", ['environment' => 'production'])->assertUnprocessable()->assertJsonPath('code', 'INT-BR-02');
});

it('T-22: error transitorio con 5 intentos y backoff termina en fallo definitivo con alerta', function () {
    $this->crmWorld();
    $error = $this->inTenant(fn () => IntegrationError::create(['source' => 'job', 'ref_code' => 'JOB-07', 'target' => 'Sync proveedor Inka Rail',
        'error' => 'Timeout 30 s', 'error_class' => 'transitorio', 'attempt' => 0, 'max_attempts' => 5, 'status' => 'reintentando']));
    $retries = [];
    for ($i = 1; $i <= 5; $i++) {
        $this->as('admin')->withHeaders($this->idem())->postJson('/api/v1/jobs/JOB-07/run')->assertOk();
        $retries[] = $this->fresh('integration_errors', $error->id)->next_retry_at;
    }
    $row = $this->fresh('integration_errors', $error->id);
    expect($row->status)->toBe('fallo_definitivo')->and((int) $row->attempt)->toBe(5)
        ->and(array_filter(array_slice($retries, 0, 4)))->toHaveCount(4)
        ->and($this->admin()->table('notif_messages')->where('event', 'integracion.fallo')->where('user_id', $this->users['ops']->id)->count())->toBe(1)
        ->and($this->admin()->table('audit_events')->where('entity', 'Job')->where('action', 'fallo_definitivo')->count())->toBe(1);
});

it('JOB-BR-01: ejecutar ahora un job en curso → 409', function () {
    $this->crmWorld();
    $this->admin()->table('sys_scheduled_jobs')->where('tenant_id', $this->tenant->id)->where('code', 'JOB-04')->update(['last_status' => 'en_curso']);
    $this->as('admin')->withHeaders($this->idem())->postJson('/api/v1/jobs/JOB-04/run')->assertStatus(409)->assertJsonPath('error', 'JOB_RUNNING');
});

// ───────────────────────────── Reportes y exportación ─────────────────────────────

it('T-23: exportar sin reports.export → 403 y exportar_denegado; con permiso → 202 y disponible', function () {
    $this->crmWorld();
    $this->as('u03')->withHeaders($this->idem())->postJson('/api/v1/reports/exports', ['report_key' => 'comercial', 'format' => 'CSV'])->assertForbidden();
    expect($this->admin()->table('audit_events')->where('action', 'exportar_denegado')->count())->toBe(1);

    $response = $this->as('manager')->withHeaders($this->idem())->postJson('/api/v1/reports/exports', ['report_key' => 'finanzas', 'format' => 'XLSX', 'filters' => ['period' => '6m']])
        ->assertStatus(202);
    $export = $this->admin()->table('report_exports')->where('id', $response->json('export_id'))->first();
    expect($export->status)->toBe('disponible')->and($export->file_path)->toEndWith('.xlsx');

    $url = $this->as('manager')->getJson("/api/v1/reports/exports/{$export->id}/download")->assertStatus(302)->json('url');
    expect($this->admin()->table('audit_events')->where('action', 'descargar')->count())->toBe(1);
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('EXP-BR-03: programar envío exige destinatarios válidos y crea un JOB-nn', function () {
    $this->crmWorld();
    $this->as('manager')->postJson('/api/v1/reports/schedules', ['report_key' => 'comercial', 'frequency' => 'lunes', 'time' => '07:30', 'format' => 'PDF', 'recipients' => 'gerencia@, x'])
        ->assertUnprocessable();
    $job = $this->as('manager')->postJson('/api/v1/reports/schedules', ['report_key' => 'comercial', 'frequency' => 'lunes', 'time' => '07:30', 'format' => 'PDF', 'recipients' => 'gerencia@andes.test'])
        ->assertCreated()->json('data');
    expect($job['code'])->toBe('JOB-20'); // JOB-10..19 son de sistema (specs 11, 12 y 14)
    $this->as('manager')->withHeaders($this->idem())->postJson('/api/v1/jobs/JOB-20/run')->assertForbidden();
    $this->as('admin')->withHeaders($this->idem())->postJson('/api/v1/jobs/JOB-20/run')->assertOk();
    expect($this->admin()->table('report_exports')->whereNull('requested_by')->where('format', 'PDF')->value('status'))->toBe('disponible');
    $this->as('manager')->deleteJson('/api/v1/jobs/JOB-20')->assertOk();
});

it('T-24: Ventas solo ve su cartera en Comercial y no accede a Finanzas', function () {
    $this->crmWorld();
    $this->makeLead('u03');
    $this->makeLead('u04');
    $this->as('u03')->getJson('/api/v1/reports/comercial?period=all')->assertOk()
        ->assertJsonPath('kpis.0.label', 'Mis leads')->assertJsonPath('kpis.0.value', 1)->assertJsonPath('meta.own_only', true);
    $this->as('manager')->getJson('/api/v1/reports/comercial?period=all')->assertOk()->assertJsonPath('kpis.0.value', 2);
    $this->as('u03')->getJson('/api/v1/reports/finanzas')->assertForbidden()->assertJsonPath('code', 'REP-BR-01');
});

it('T-25: Contabilidad ve Finanzas sin costo, utilidad ni margen', function () {
    $this->crmWorld();
    $body = $this->as('accounting')->getJson('/api/v1/reports/finanzas')->assertOk();
    $columns = collect($body->json('table.columns'))->pluck(0)->all();
    expect($columns)->not->toContain('cost')->not->toContain('profit')->not->toContain('margin')
        ->and(collect($body->json('kpis'))->pluck('key')->all())->not->toContain('profit')
        ->and($body->json('variant'))->toBe('contable');
});

// ───────────────────────────── Panel ejecutivo ─────────────────────────────

it('T-28: Manager aprueba una cotización fuera de política solo con motivo; queda auditada y notificada', function () {
    $this->quoteWorld();
    $quote = $this->makeQuote('u03', ['discount_pct' => 8]);
    $approvals = $this->as('manager')->getJson('/api/v1/dashboard/manager')->assertOk()->json('data.approvals.quotes');
    expect(collect($approvals)->pluck('code')->all())->toContain($quote['code']);
    $this->as('admin')->getJson('/api/v1/dashboard/executive')->assertOk()->assertJsonPath('data.kpis.0.key', 'sales');

    $this->qPost('manager', "quotes/{$quote['code']}/versions/1/internal-approval", ['decision' => 'approve'], $this->quoteLock($quote['code']))->assertUnprocessable();
    $this->qPost('manager', "quotes/{$quote['code']}/versions/1/internal-approval", ['decision' => 'approve', 'note' => 'Cliente corporativo recurrente'], $this->quoteLock($quote['code']))
        ->assertOk();
    expect($this->admin()->table('audit_events')->where('entity', 'Cotización')->where('action', 'aprobacion_interna')->count())->toBe(1)
        ->and($this->admin()->table('notif_messages')->where('user_id', $this->users['u03']->id)->where('event', 'cotizacion.aprobacion_aprobada')->count())->toBe(1);
    $after = $this->as('manager')->getJson('/api/v1/dashboard/manager')->json('data.approvals.quotes');
    expect(collect($after)->pluck('code')->all())->not->toContain($quote['code']);
});
