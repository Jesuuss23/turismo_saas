<?php

namespace App\Security;

/**
 * ROLE_UX (§7.4): experiencia, inicio, rutas permitidas y navegación de cada rol.
 */
final class RoleUx
{
    public const PLATFORM_ROUTES = ['admin/tenants', 'admin/plataforma', 'admin/integraciones', 'admin/auditoria'];

    /**
     * @return array{experience: string, layout: string, label: string, home: string, routes: list<string>|null, nav: list<array{route: string, label: string}>}
     *                                                                                                                                                           routes null = según ACCESS (back-office)
     */
    public static function for(string $role, ?string $scope = null, ?string $clientId = null): array
    {
        return match ($role) {
            RoleCatalog::SUPER_ADMIN => [
                'experience' => 'platform', 'layout' => 'backoffice', 'label' => 'Consola de plataforma',
                'home' => 'admin/tenants', 'routes' => self::PLATFORM_ROUTES, 'nav' => [],
            ],
            'guide', 'driver' => self::operatorApp(),
            'passenger' => [
                'experience' => 'portal', 'layout' => 'portal', 'label' => 'Portal del pasajero',
                'home' => 'canales/portal-pasajero/viajes',
                'routes' => ['canales/portal-pasajero', 'mobile/pasajero', 'libro-reclamaciones'],
                'nav' => self::nav('canales/portal-pasajero', [
                    'viajes' => 'Mi viaje', 'itinerario' => 'Itinerario', 'vouchers' => 'Vouchers',
                    'alertas' => 'Alertas', 'contacto' => 'Contacto',
                ], ['mobile/pasajero' => 'App móvil', 'libro-reclamaciones' => 'Libro de reclamaciones']),
            ],
            'b2b' => [
                'experience' => 'portal', 'layout' => 'portal', 'label' => 'Portal de agencias',
                'home' => 'canales/b2b/portal/catalogo',
                'routes' => ['canales/b2b/portal'],
                'nav' => self::nav('canales/b2b/portal', [
                    'catalogo' => 'Catálogo y tarifas', 'reservas' => 'Mis reservas', 'estado-cuenta' => 'Estado de cuenta',
                ]),
            ],
            'customer' => [
                'experience' => 'portal', 'layout' => 'portal', 'label' => 'Portal del cliente',
                'home' => "canales/portal-cliente/{$clientId}/inicio",
                'routes' => ["canales/portal-cliente/{$clientId}", 'libro-reclamaciones'],
                'nav' => self::nav("canales/portal-cliente/{$clientId}", [
                    'inicio' => 'Inicio', 'cotizaciones' => 'Cotizaciones', 'viajes' => 'Mis viajes',
                    'documentos' => 'Documentos', 'pagos' => 'Pagos', 'soporte' => 'Soporte',
                ], ['libro-reclamaciones' => 'Libro de reclamaciones']),
            ],
            'provider' => [
                'experience' => 'portal', 'layout' => 'portal', 'label' => 'Extranet de proveedores',
                'home' => 'proveedor/solicitudes',
                'routes' => ['proveedor'],
                'nav' => self::nav('proveedor', [
                    'solicitudes' => 'Solicitudes', 'rooming' => 'Rooming lists', 'vouchers' => 'Vouchers',
                    'pagos' => 'Pagos', 'incidencias' => 'Incidencias', 'contrato' => 'Mi contrato',
                ]),
            ],
            // Roles internos y personalizados: heredan la experiencia de su scope.
            default => $scope === 'mobile' ? self::operatorApp() : self::backoffice(),
        };
    }

    private static function backoffice(): array
    {
        return [
            'experience' => 'backoffice', 'layout' => 'backoffice', 'label' => 'Back-office interno',
            'home' => 'dashboard', 'routes' => null, 'nav' => [],
        ];
    }

    private static function operatorApp(): array
    {
        return [
            'experience' => 'mobile', 'layout' => 'mobile', 'label' => 'App del operador',
            'home' => 'mobile/operador', 'routes' => ['mobile/operador'], 'nav' => [],
        ];
    }

    private static function nav(string $base, array $items, array $extra = []): array
    {
        $nav = [];
        foreach ($items as $slug => $label) {
            $nav[] = ['route' => "{$base}/{$slug}", 'label' => $label];
        }
        foreach ($extra as $route => $label) {
            $nav[] = ['route' => $route, 'label' => $label];
        }

        return $nav;
    }
}
