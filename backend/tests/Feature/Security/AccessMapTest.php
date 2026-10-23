<?php

use App\Security\Access;
use App\Security\AccessSubject;
use App\Security\RoleCatalog;

/*
| X-06: Access::check por rol coincide con la tabla §7.5 de la spec, y GET /me/access la expone.
*/

const ACCESS_COLUMNS = ['SA' => 'super_admin', 'TA' => 'tenant_admin', 'MG' => 'manager', 'VT' => 'sales', 'OP' => 'operations', 'FI' => 'finance', 'CO' => 'accounting', 'GU' => 'guide', 'CD' => 'driver', 'B2B' => 'b2b', 'CL' => 'customer', 'PA' => 'passenger', 'PR' => 'provider'];

function subjectFor(string $role): AccessSubject
{
    $permissions = $role === 'super_admin' ? RoleCatalog::SUPER_ADMIN_PERMISSIONS : RoleCatalog::matrix()[$role];
    $scope = RoleCatalog::tenantRoles()[$role]['scope'] ?? null;

    return new AccessSubject($role, $permissions, $scope, $role, $role === 'customer' ? 'cli-0001' : null);
}

function specAccessRows(): array
{
    $rows = specTable('7.5 ACCESS');
    $header = array_shift($rows);

    $cases = [];
    foreach ($rows as $row) {
        $route = trim($row[0], '`');
        if (str_starts_with($route, 'sitio')) {
            continue;
        }
        $route = str_replace('/*', '/catalogo', $route);
        foreach (array_slice($header, 2) as $i => $col) {
            $cases[] = [$route, ACCESS_COLUMNS[$col], str_starts_with($row[$i + 2], '✓')];
        }
    }

    return $cases;
}

it('X-06: Access::check reproduce la tabla §7.5 para los 13 roles', function () {
    $mismatches = [];
    foreach (specAccessRows() as [$route, $role, $expected]) {
        $path = $role === 'customer' && $route === 'canales/portal-cliente' ? 'canales/portal-cliente/cli-0001' : $route;
        if (Access::check(subjectFor($role), $path)->allowed !== $expected) {
            $mismatches[] = "{$role} {$route} esperado ".($expected ? '✓' : '·');
        }
    }

    expect($mismatches)->toBe([]);
});

it('usa el prefijo definido más largo', function () {
    $sales = subjectFor('sales');
    expect(Access::check($sales, 'operaciones/documentos-viaje')->allowed)->toBeTrue()
        ->and(Access::check($sales, 'operaciones/ordenes')->allowed)->toBeFalse()
        ->and(Access::check($sales, 'operaciones/ordenes')->required)->toBe(['operations.view']);
});

it('un cliente que abre el portal de otro cliente es redirigido al suyo', function () {
    $decision = Access::check(subjectFor('customer'), 'canales/portal-cliente/cli-0002/inicio');

    expect($decision->allowed)->toBeFalse()
        ->and($decision->redirect)->toBe('canales/portal-cliente/cli-0001/inicio');
});

it('externos reciben el mensaje de back-office (SEC-BR-05)', function () {
    $decision = Access::check(subjectFor('guide'), 'dashboard');

    expect($decision->allowed)->toBeFalse()
        ->and($decision->reason)->toContain('back-office interno');
});

it('sin sesión pide login y el sitio público es libre', function () {
    expect(Access::check(null, 'dashboard')->redirect)->toBe('login')
        ->and(Access::check(null, 'sitio/tours')->allowed)->toBeTrue();
});

it('X-06: GET /me/access expone experiencia, inicio y pantallas por rol', function () {
    $tenant = $this->makeTenant();

    $expectations = [
        'tenant_admin' => ['backoffice', 'dashboard'],
        'sales' => ['backoffice', 'dashboard'],
        'guide' => ['mobile', 'mobile/operador'],
        'b2b' => ['portal', 'canales/b2b/portal/catalogo'],
        'passenger' => ['portal', 'canales/portal-pasajero/viajes'],
        'provider' => ['portal', 'proveedor/solicitudes'],
    ];

    foreach ($expectations as $role => [$experience, $home]) {
        $token = $this->tokenFor($this->makeUser($tenant, $role));
        $response = $this->api($token)->getJson('/api/v1/me/access')->assertOk();

        expect($response->json('experience'))->toBe($experience)
            ->and($response->json('home'))->toBe($home);
        foreach (specAccessRows() as [$route, $r, $allowed]) {
            if ($r === $role && array_key_exists($route, $response->json('screens'))) {
                expect($response->json("screens.{$route}"))->toBe($allowed, "{$role} {$route}");
            }
        }
    }
});

it('GET /me/access/check decide una pantalla y audita el denegado (SEC-BR-07)', function () {
    $tenant = $this->makeTenant();
    $token = $this->tokenFor($this->makeUser($tenant, 'operations'));

    $this->api($token)->getJson('/api/v1/me/access/check?path=/catalogo/proveedores')->assertOk()->assertJsonPath('allowed', true);
    $this->api($token)->getJson('/api/v1/me/access/check?path=/finanzas/rentabilidad')->assertOk()
        ->assertJsonPath('allowed', false)
        ->assertJsonPath('required_permissions', ['finance.profitability'])
        ->assertJsonPath('can_request_access', true);

    $event = $this->admin()->table('audit_events')->where('action', 'acceso_denegado')->first();
    expect($event->entity_id)->toBe('/finanzas/rentabilidad')
        ->and($event->before_value)->toBe('operations')
        ->and($event->after_value)->toBe('requiere finance.profitability');
});
