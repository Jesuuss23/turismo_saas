<?php

use App\Models\Iam\AccessRequest;
use App\Models\Iam\User;
use App\Services\Auth\TokenIssuer;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->a = $this->makeTenant('andes');
    $this->b = $this->makeTenant('inka');
    $this->adminA = $this->makeUser($this->a, 'tenant_admin');
    $this->adminB = $this->makeUser($this->b, 'tenant_admin');

    $this->requestB = app(TenantContext::class)->run($this->b, fn () => AccessRequest::create([
        'user_id' => $this->adminB->id, 'path' => 'admin/roles', 'status' => 'pendiente', 'created_at' => now(),
    ]));
    app(TenantContext::class)->run($this->a, fn () => AccessRequest::create([
        'user_id' => $this->adminA->id, 'path' => 'admin/api', 'status' => 'pendiente', 'created_at' => now(),
    ]));
});

it('X-01 / E2E-08: con token de A, un recurso de B responde 404 y se audita', function () {
    $this->api($this->tokenFor($this->adminA))
        ->getJson("/api/v1/access-requests/{$this->requestB->id}")
        ->assertNotFound()
        ->assertJsonPath('error', 'RESOURCE_NOT_FOUND');

    $event = $this->admin()->table('audit_events')->where('action', 'acceso_denegado')->first();
    expect($event->tenant_id)->toBe($this->a->id)
        ->and($event->after_value)->toBe('recurso de otro tenant (404)');
});

it('X-01: RLS devuelve 0 filas aun sin el scope de Eloquent', function () {
    app(TenantContext::class)->run($this->a, function () {
        expect(AccessRequest::withoutGlobalScopes()->find($this->requestB->id))->toBeNull()
            ->and(DB::table('iam_access_requests')->where('id', $this->requestB->id)->count())->toBe(0)
            ->and(DB::table('iam_users')->count())->toBe(1);
    });
});

it('X-02: X-Tenant-Id y ?tenant_id del cliente se ignoran', function () {
    $response = $this->api($this->tokenFor($this->adminA))
        ->withHeader('X-Tenant-Id', $this->b->id)
        ->getJson("/api/v1/access-requests?tenant_id={$this->b->id}")
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.path'))->toBe('admin/api');
});

it('X-02: una ruta que fija otro tenant responde 403 TENANT_SCOPE_VIOLATION y se audita', function () {
    Route::middleware(['api', 'auth:sanctum', 'tenant'])->get('/api/v1/_test/tenants/{tenant}/ping', fn () => ['ok' => true]);

    $token = $this->tokenFor($this->adminA);
    $this->api($token)->getJson("/api/v1/_test/tenants/{$this->a->id}/ping")->assertOk();
    $this->api($token)->getJson("/api/v1/_test/tenants/{$this->b->id}/ping")
        ->assertForbidden()
        ->assertJsonPath('error', 'TENANT_SCOPE_VIOLATION');

    expect($this->admin()->table('audit_events')->where('action', 'acceso_denegado')->where('after_value', 'intento de fijar otro tenant')->count())->toBe(1);
});

it('X-02: el subdominio de otro tenant se rechaza', function () {
    $this->api($this->tokenFor($this->adminA))
        ->getJson('http://inka.turismo.test/api/v1/me')
        ->assertForbidden()
        ->assertJsonPath('error', 'TENANT_SCOPE_VIOLATION');
});

it('X-03: sin app.tenant_id, SELECT devuelve 0 filas e INSERT viola la política', function () {
    app(TenantContext::class)->clear();

    expect(DB::table('iam_users')->count())->toBe(0)
        ->and(DB::table('iam_sedes')->count())->toBe(0)
        ->and(DB::table('roles')->whereNotNull('tenant_id')->count())->toBe(0);

    expect(fn () => DB::table('iam_access_requests')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->a->id, 'user_id' => $this->adminA->id,
        'path' => 'x', 'status' => 'pendiente', 'created_at' => now(),
    ]))->toThrow(QueryException::class, 'row-level security');
});

it('no se puede insertar con tenant_id de otro tenant aunque haya contexto', function () {
    app(TenantContext::class)->run($this->a, function () {
        expect(fn () => DB::table('iam_access_requests')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->b->id, 'user_id' => $this->adminB->id,
            'path' => 'x', 'status' => 'pendiente', 'created_at' => now(),
        ]))->toThrow(QueryException::class, 'row-level security');
    });
});

it('el tenant_id de un modelo siempre lo impone el contexto', function () {
    $created = app(TenantContext::class)->run($this->a, fn () => AccessRequest::create([
        'user_id' => $this->adminA->id, 'path' => 'crm', 'status' => 'pendiente', 'created_at' => now(), 'tenant_id' => $this->b->id,
    ]));

    expect($created->tenant_id)->toBe($this->a->id);
});

it('la app no puede escribir en el catálogo global ni dar permisos directos', function () {
    app(TenantContext::class)->run($this->a, function () {
        expect(fn () => DB::table('permissions')->insert(['name' => 'x.y', 'guard_name' => 'api', 'module' => 'X', 'description' => 'x']))
            ->toThrow(QueryException::class, 'permission denied');
        expect(fn () => $this->adminA->givePermissionTo('reports.view'))
            ->toThrow(QueryException::class, 'permission denied');
    });
});

it('X-10: el Super Admin no accede a endpoints de tenant', function () {
    $platform = createPlatformUser();
    $token = app(TokenIssuer::class)->issue($platform, false)->plainTextToken;

    $this->api($token)->getJson('/api/v1/access-requests')->assertForbidden();
    $this->api($token)->getJson('/api/v1/me')->assertForbidden();
});

it('X-12: usuario desactivado → 401', function () {
    $token = $this->tokenFor($this->adminA);
    app(TenantContext::class)->run($this->a, fn () => User::find($this->adminA->id)->forceFill(['status' => User::INACTIVE])->save());
    app(TenantContext::class)->clear();

    $this->api($token)->getJson('/api/v1/me')->assertUnauthorized();
});

it('X-12 / SEC-BR-24: tenant suspendido → 403 TENANT_SUSPENDED en solicitudes y login', function () {
    $token = $this->tokenFor($this->adminA);
    $this->admin()->table('tenants')->where('id', $this->a->id)->update(['status' => 'suspendido', 'suspend_reason' => 'Falta de pago']);

    $this->api($token)->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('error', 'TENANT_SUSPENDED');
    $this->postJson('/api/v1/auth/login', ['email' => $this->adminA->email, 'password' => 'Secreta123!', 'tenant_slug' => 'andes'])
        ->assertForbidden()->assertJsonPath('error', 'TENANT_SUSPENDED');
});

it('SEC-BR-05: usuarios externos no acceden a endpoints de back-office', function () {
    $guide = $this->makeUser($this->a, 'guide', ['guide_id' => (string) Str::uuid()]);

    $this->api($this->tokenFor($guide))->getJson('/api/v1/me/tenant')
        ->assertForbidden()
        ->assertJsonPath('code', 'SEC-BR-05')
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'back-office interno'));
});
