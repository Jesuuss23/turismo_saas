<?php

use App\Models\Iam\Role;
use App\Security\PermissionCatalog;
use App\Security\RoleCatalog;
use App\Tenancy\TenantContext;

/*
| X-05: la matriz sembrada (y copiada al tenant) coincide celda a celda con la tabla §7.3 de la spec.
*/

const MATRIX_COLUMNS = ['SA' => 'super_admin', 'TA' => 'tenant_admin', 'MG' => 'manager', 'VT' => 'sales', 'OP' => 'operations', 'FI' => 'finance', 'CO' => 'accounting', 'GU' => 'guide', 'CD' => 'driver', 'B2B' => 'b2b', 'CL' => 'customer', 'PA' => 'passenger', 'PR' => 'provider'];

function specMatrix(): array
{
    $rows = specTable('7.3 Matriz completa');
    $header = array_shift($rows);
    $matrix = [];
    foreach ($rows as $row) {
        if (str_starts_with($row[0], '**')) {
            continue;
        }
        foreach (array_slice($header, 1) as $i => $col) {
            $matrix[MATRIX_COLUMNS[$col]][$row[0]] = $row[$i + 1] === '✓';
        }
    }

    return $matrix;
}

function basePermissions(): array
{
    return array_values(array_diff(PermissionCatalog::names(), array_keys(PermissionCatalog::MODULE_ADDITIONS)));
}

it('tiene los 57 permisos del catálogo §7.1 más los agregados por módulo', function () {
    expect(basePermissions())->toHaveCount(57)
        ->and(array_keys(specMatrix()['tenant_admin']))->toEqualCanonicalizing(basePermissions());
});

it('los permisos agregados por módulo solo los reciben sus roles semilla', function () {
    foreach (PermissionCatalog::MODULE_ADDITIONS as $permission => [$roles]) {
        foreach (RoleCatalog::matrix() as $role => $permissions) {
            expect(in_array($permission, $permissions, true))->toBe(in_array($role, $roles, true), "{$role} / {$permission}");
        }
    }
});

it('reproduce los totales por rol de la spec', function () {
    $totals = specTable('7.3 Matriz completa');
    $header = $totals[0];
    $last = end($totals);
    foreach (array_slice($header, 1) as $i => $col) {
        $expected = (int) trim($last[$i + 1], '*');
        $role = MATRIX_COLUMNS[$col];
        $actual = $role === 'super_admin'
            ? count(RoleCatalog::SUPER_ADMIN_PERMISSIONS)
            : count(array_intersect(RoleCatalog::matrix()[$role], basePermissions()));
        expect($actual)->toBe($expected, "Total de {$role}");
    }
});

it('X-05: cada rol semilla del tenant tiene exactamente los permisos de §7.3', function () {
    $tenant = $this->makeTenant();
    $spec = specMatrix();

    foreach (array_keys(RoleCatalog::tenantRoles()) as $role) {
        $user = $this->makeUser($tenant, $role);

        app(TenantContext::class)->run($tenant, function () use ($user, $spec, $role) {
            foreach ($spec[$role] as $permission => $granted) {
                expect($user->hasPermissionTo($permission))->toBe($granted, "{$role} / {$permission}");
            }
        });
    }

    foreach ($spec['super_admin'] as $permission => $granted) {
        expect(in_array($permission, RoleCatalog::SUPER_ADMIN_PERMISSIONS, true))->toBe($granted, "super_admin / {$permission}");
    }
});

it('X-05: un endpoint protegido responde 200/403 según la matriz', function () {
    $tenant = $this->makeTenant();
    $spec = specMatrix();

    foreach (['tenant_admin', 'manager', 'sales', 'operations', 'finance', 'accounting'] as $role) {
        $token = $this->tokenFor($this->makeUser($tenant, $role));
        $this->api($token)->getJson('/api/v1/access-requests')
            ->assertStatus($spec[$role]['admin.users.manage'] ? 200 : 403);
    }
});

it('las matrices son independientes por tenant', function () {
    $a = $this->makeTenant('andes');
    $b = $this->makeTenant('inka');
    $userB = $this->makeUser($b, 'sales');

    // El Tenant Admin de A quita un permiso a su rol Ventas.
    app(TenantContext::class)->run($a, fn () => Role::where('name', 'sales')->where('tenant_id', $a->id)->first()->revokePermissionTo('quotes.send'));

    app(TenantContext::class)->run($b, function () use ($userB) {
        expect($userB->fresh()->hasPermissionTo('quotes.send'))->toBeTrue();
    });
});
