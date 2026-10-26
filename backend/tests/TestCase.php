<?php

namespace Tests;

use App\Models\Iam\User;
use App\Models\Tenant;
use App\Services\Auth\TokenIssuer;
use App\Services\TenantProvisioner;
use App\Tenancy\TenantContext;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CatalogWorld;
use Tests\Concerns\CrmWorld;

/**
 * Las pruebas corren contra PostgreSQL real (turismo_test) para ejercitar RLS:
 * - la app usa la conexión `pgsql` (rol sin BYPASSRLS), igual que en producción;
 * - la preparación de datos usa `pgsql_admin`.
 * No se usan transacciones de prueba (cada conexión vería datos distintos); se trunca entre pruebas.
 */
abstract class TestCase extends BaseTestCase
{
    use CatalogWorld, CrmWorld;

    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$migrated) {
            Artisan::call('migrate:fresh', ['--database' => 'pgsql_admin', '--force' => true]);
            self::$migrated = true;
        }

        DB::connection('pgsql_admin')->statement('TRUNCATE tenants, platform_users, permissions, roles, personal_access_tokens, audit_events, sys_idempotency_keys, sys_outbox, failed_jobs RESTART IDENTITY CASCADE');
        (new PermissionCatalogSeeder)->run();
        app(TenantContext::class)->clear();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    protected function makeTenant(string $slug = 'andes', array $settings = ['security' => ['mfa_internal_required' => false]]): Tenant
    {
        return app(TenantProvisioner::class)->provision(['slug' => $slug, 'legal_name' => Str::title($slug).' S.A.C.', 'settings' => $settings]);
    }

    protected function makeUser(Tenant $tenant, string $role, array $attributes = []): User
    {
        return app(TenantContext::class)->run($tenant, function () use ($tenant, $role, $attributes) {
            $user = User::create($attributes + [
                'code' => 'U'.Str::upper(Str::random(5)),
                'name' => Str::title($role).' '.Str::random(4),
                'email' => Str::lower(Str::random(8)).'@'.$tenant->slug.'.test',
                'password' => 'Secreta123!',
                'sede_id' => DB::connection('pgsql_admin')->table('iam_sedes')->where('tenant_id', $tenant->id)->value('id'),
                'status' => User::ACTIVE,
            ]);
            $user->assignRole($role);

            return $user->fresh();
        });
    }

    /** Token completo (MFA ya verificado) para pruebas HTTP. */
    protected function tokenFor(User $user, bool $mfaPending = false): string
    {
        $token = app(TenantContext::class)->run($user->tenant_id, fn () => app(TokenIssuer::class)->issue($user->load('roles'), $mfaPending)->plainTextToken);
        app(TenantContext::class)->clear();

        return $token;
    }

    protected function api(string $token): static
    {
        // Cada solicitud resuelve de nuevo el guard (como en producción, una solicitud por token).
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }

    protected function admin(): Connection
    {
        return DB::connection('pgsql_admin');
    }
}
