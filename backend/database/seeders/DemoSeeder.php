<?php

namespace Database\Seeders;

use App\Models\Crm\Client;
use App\Models\Iam\User;
use App\Security\RoleCatalog;
use App\Services\TenantProvisioner;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Datos de demostración: tenant “andes” con los usuarios de prueba U01…U17 y un segundo
 * tenant “inka” para verificar el aislamiento (E2E-08), más el Super Admin de plataforma.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Turismo2026!';

    /** Secreto TOTP fijo SOLO para demo (añádalo a su app autenticadora). */
    public const PLATFORM_MFA_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    /**
     * code => [nombre, email, rol, vínculos]. Roles según spec 01 §7.2 (U05/U06 Operaciones, U07 Finanzas,
     * U08 Contabilidad; U16 es el Super Admin de plataforma). Los clientes (CLI-xxxx) los crea
     * CrmDemoSeeder; los demás vínculos (AGB-01, G01…) usan ids deterministas hasta que existan sus módulos.
     */
    public const USERS = [
        'U01' => ['Lucía Paredes', 'admin@andes.test', 'tenant_admin', []],
        'U02' => ['Martín Quispe', 'gerencia@andes.test', 'manager', []],
        'U03' => ['Carla Mendoza', 'carla.ventas@andes.test', 'sales', []],
        'U04' => ['Diego Salazar', 'diego.ventas@andes.test', 'sales', []],
        'U05' => ['Rosa Huamán', 'operaciones@andes.test', 'operations', []],
        'U06' => ['Javier Torres', 'javier.operaciones@andes.test', 'operations', []],
        'U07' => ['Elena Rojas', 'finanzas@andes.test', 'finance', []],
        'U08' => ['Pedro Castillo', 'contabilidad@andes.test', 'accounting', []],
        'U09' => ['Wilber Condori', 'guia1@andes.test', 'guide', ['guide_id' => 'G01']],
        'U10' => ['Nadia Flores', 'guia2@andes.test', 'guide', ['guide_id' => 'G02']],
        'U11' => ['Raúl Mamani', 'conductor@andes.test', 'driver', ['driver_id' => 'C01']],
        'U12' => ['Sofía Vargas', 'sofia.operaciones@andes.test', 'operations', []],
        'U13' => ['Agencia Sol Viajes', 'b2b@solviajes.test', 'b2b', ['agency_id' => 'AGB-01', 'client_id' => 'CLI-0007']],
        'U14' => ['Ana Gutiérrez', 'cliente@correo.test', 'customer', ['client_id' => 'CLI-0001']],
        'U15' => ['John Miller', 'pasajero@correo.test', 'passenger', ['client_id' => 'CLI-0003', 'reservation_id' => 'RES-2026-000347']],
        'U17' => ['Hotel Inti Wasi', 'reservas@intiwasi.test', 'provider', ['provider_id' => 'PRV-001']],
    ];

    public function run(): void
    {
        $context = app(TenantContext::class);
        $provisioner = app(TenantProvisioner::class);

        $andes = $provisioner->provision([
            'slug' => 'andes', 'legal_name' => 'Andes Travel Perú S.A.C.', 'trade_name' => 'Andes Travel',
            'ruc' => '20601234567', 'settings' => ['security' => ['mfa_internal_required' => false]],
        ], 'Lima - Miraflores');
        $inka = $provisioner->provision([
            'slug' => 'inka', 'legal_name' => 'Inka Expeditions E.I.R.L.', 'trade_name' => 'Inka Expeditions',
            'ruc' => '20609876543', 'settings' => ['security' => ['mfa_internal_required' => false]],
        ], 'Cusco - Centro');

        $context->run($andes, function () use ($andes) {
            DB::table('iam_sedes')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $andes->id, 'code' => 'S02', 'name' => 'Cusco - San Blas', 'city' => 'Cusco', 'created_at' => now(), 'updated_at' => now()]);
            // Internos primero: los clientes CRM necesitan responsable y los externos se vinculan a clientes.
            $external = array_filter(self::USERS, fn ($u) => RoleCatalog::isExternal($u[2]));
            foreach (array_diff_key(self::USERS, $external) as $code => [$name, $email, $role, $links]) {
                $this->createUser($code, $name, $email, $role, $links);
            }
            (new CrmDemoSeeder)->run();
            (new CatalogDemoSeeder)->run();
            foreach ($external as $code => [$name, $email, $role, $links]) {
                $this->createUser($code, $name, $email, $role, $links);
            }
        });

        $context->run($inka, function () {
            $admin = $this->createUser('U01', 'Mario Inca', 'admin@inka.test', 'tenant_admin', []);
            $this->createUser('U02', 'Paola Sánchez', 'ventas@inka.test', 'sales', []);
            CatalogDemoSeeder::minimal($admin);
        });

        DB::table('platform_users')->insert([
            'id' => (string) Str::uuid7(),
            'name' => 'Super Admin SaaS',
            'email' => 'superadmin@turismo.test',
            'password' => Hash::make(self::PASSWORD),
            'mfa_secret' => encrypt(self::PLATFORM_MFA_SECRET),
            'status' => 'activo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->command?->info('Demo lista. Contraseña de todos: '.self::PASSWORD.' · tenants: andes, inka');
        $this->command?->info('Super Admin: superadmin@turismo.test · secreto TOTP demo '.self::PLATFORM_MFA_SECRET);
    }

    public static function linkId(string $code): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'turismo:'.$code)->toString();
    }

    private function createUser(string $code, string $name, string $email, string $role, array $links): User
    {
        $sede = DB::table('iam_sedes')->where('tenant_id', app(TenantContext::class)->id())->where('is_main', true)->value('id');

        $user = User::create([
            'code' => $code,
            'name' => $name,
            'email' => $email,
            'password' => self::PASSWORD,
            'sede_id' => $sede,
            'status' => User::ACTIVE,
            'avatar_color' => sprintf('#%06x', crc32($code.$email) & 0xFFFFFF),
            ...array_map(fn (string $ref) => str_starts_with($ref, 'CLI-')
                ? (Client::where('code', $ref)->value('id') ?? self::linkId($ref))
                : self::linkId($ref), $links),
        ]);
        $user->assignRole($role);

        return $user;
    }
}
