<?php

namespace App\Security;

/**
 * ACCESS (§7.5): permiso requerido por pantalla, “cualquiera de”. `*` = cualquier rol interno.
 * Cada entrada lleva su etiqueta y grupo de menú para construir la navegación del back-office.
 */
final class AccessMap
{
    public const ANY_INTERNAL = '*';

    public const EXTRANET_ONLY = '__solo_extranet__';

    /** @var array<string, array{0: list<string>, 1: string, 2: string|null}> ruta => [permisos, etiqueta, grupo de menú] */
    public const SCREENS = [
        'dashboard' => [[self::ANY_INTERNAL], 'Dashboard', 'Inicio'],
        'flujo' => [[self::ANY_INTERNAL], 'Flujo del negocio', 'Inicio'],
        'crm' => [['crm.leads.view'], 'CRM', null],
        'crm/leads' => [['crm.leads.view'], 'Leads', 'CRM'],
        'crm/oportunidades' => [['crm.opportunities.manage'], 'Oportunidades', 'CRM'],
        'crm/actividades' => [['crm.activities.manage'], 'Actividades', 'CRM'],
        'crm/clientes' => [['crm.clients.manage', 'finance.receivables'], 'Clientes', 'CRM'],
        'crm/postventa' => [['crm.clients.manage'], 'Postventa', 'CRM'],
        'crm/metas' => [['crm.targets.view', 'crm.targets.manage'], 'Metas comerciales', 'CRM'],
        'crm/reclamaciones' => [['crm.claims.manage'], 'Reclamaciones', 'CRM'],
        'libro-reclamaciones' => [['crm.claims.manage'], 'Libro de reclamaciones', null],
        'marketing' => [['marketing.campaigns.manage'], 'Marketing', null],
        'marketing/campanas' => [['marketing.campaigns.manage'], 'Campañas', 'Marketing'],
        'catalogo' => [['catalog.products.view'], 'Catálogo', null],
        'catalogo/productos' => [['catalog.products.view'], 'Productos', 'Catálogo'],
        'catalogo/salidas' => [['catalog.products.view'], 'Salidas', 'Catálogo'],
        'catalogo/tarifas' => [['catalog.products.view'], 'Tarifas', 'Catálogo'],
        'catalogo/servicios' => [['catalog.products.view'], 'Servicios', 'Catálogo'],
        'catalogo/destinos' => [['catalog.products.view'], 'Destinos', 'Catálogo'],
        'catalogo/proveedores' => [['catalog.providers.manage', 'finance.payables', 'catalog.products.update'], 'Proveedores', 'Catálogo'],
        'catalogo/categorias' => [['catalog.products.update'], 'Categorías', 'Catálogo'],
        'catalogo/cupos' => [['catalog.allotments.manage', 'catalog.products.view_profitability'], 'Cupos', 'Catálogo'],
        'cotizaciones' => [['quotes.view'], 'Cotizaciones', 'Ventas'],
        'reservas' => [['reservations.view'], 'Reservas', 'Ventas'],
        'operaciones' => [['operations.view'], 'Operaciones', null],
        'operaciones/ordenes' => [['operations.view'], 'Órdenes operativas', 'Operaciones'],
        'operaciones/calendario' => [['operations.view'], 'Calendario', 'Operaciones'],
        'operaciones/asignaciones' => [['operations.view'], 'Asignaciones y recursos', 'Operaciones'],
        'operaciones/rooming' => [['operations.view'], 'Rooming lists', 'Operaciones'],
        'operaciones/vouchers' => [['operations.view'], 'Vouchers', 'Operaciones'],
        'operaciones/incidencias' => [['operations.view'], 'Incidencias', 'Operaciones'],
        'operaciones/proveedores' => [['operations.view'], 'Respuestas de proveedores', 'Operaciones'],
        'operaciones/documentos-viaje' => [['operations.travel_docs', 'reservations.create'], 'Documentos de viaje', 'Operaciones'],
        'finanzas' => [['finance.receivables', 'finance.invoicing'], 'Finanzas', null],
        'finanzas/cobros' => [['finance.receivables'], 'Cobros', 'Finanzas'],
        'finanzas/pagos' => [['finance.payments.register', 'finance.receivables'], 'Pagos', 'Finanzas'],
        'finanzas/cuentas-por-pagar' => [['finance.payables'], 'Cuentas por pagar', 'Finanzas'],
        'finanzas/comisiones' => [['finance.commissions'], 'Comisiones', 'Finanzas'],
        'finanzas/rentabilidad' => [['finance.profitability'], 'Rentabilidad', 'Finanzas'],
        'finanzas/facturacion' => [['finance.invoicing'], 'Facturación', 'Finanzas'], // P-12: sin `finance.profitability` (Manager no ve un menú inutilizable)
        'finanzas/tesoreria' => [['finance.treasury'], 'Tesorería', 'Finanzas'],
        'finanzas/conciliacion' => [['finance.payments.register'], 'Conciliación', 'Finanzas'], // P-12
        'canales/b2b' => [['channels.b2b.manage'], 'Canal B2B', 'Canales'],
        'canales/ecommerce' => [['channels.ecommerce.manage'], 'E-commerce', 'Canales'],
        'canales/cms' => [['channels.cms.publish'], 'CMS del sitio', 'Canales'],
        'canales/resenas' => [['admin.settings.manage', 'channels.ecommerce.manage', 'crm.clients.manage'], 'Reseñas externas', 'Canales'],
        'canales/integracion-web' => [['admin.settings.manage', 'channels.ecommerce.manage'], 'Integración web', 'Canales'],
        'canales/portal-cliente' => [['channels.b2b.manage', 'channels.cms.publish', 'crm.clients.manage'], 'Portal del cliente', 'Canales'],
        'canales/portal-pasajero' => [['channels.b2b.manage', 'channels.cms.publish', 'operations.assign'], 'Portal del pasajero', 'Canales'],
        'mobile' => [['operations.assign'], 'Apps móviles', null],
        'mobile/operador' => [['operations.assign'], 'App del operador', 'Operaciones'],
        'mobile/pasajero' => [['operations.assign'], 'App del pasajero', 'Operaciones'],
        'proveedor' => [[self::EXTRANET_ONLY], 'Extranet de proveedores', null],
        'reportes' => [['reports.view'], 'Reportes', 'Análisis'],
        'aprobaciones' => [['approvals.view'], 'Aprobaciones', 'Colaboración'],
        'inbox' => [['inbox.view'], 'Inbox', 'Colaboración'],
        'admin' => [['admin.users.manage', 'admin.tenant.manage'], 'Administración', null],
        'admin/tenants' => [['admin.tenant.manage'], 'Tenants', 'Plataforma'],
        'admin/plataforma' => [['admin.tenant.manage'], 'Plataforma', 'Plataforma'],
        'admin/plataforma/facturacion' => [['admin.tenant.manage'], 'Facturación SaaS', 'Plataforma'],
        'admin/usuarios' => [['admin.users.manage'], 'Usuarios', 'Administración'],
        'admin/roles' => [['admin.roles.manage'], 'Roles y permisos', 'Administración'],
        'admin/configuracion' => [['admin.settings.manage'], 'Configuración', 'Administración'],
        'admin/reglas' => [['admin.settings.manage'], 'Reglas de reserva', 'Administración'],
        'admin/comunicaciones' => [['admin.settings.manage', 'operations.assign'], 'Mensajes al pasajero', 'Administración'],
        'admin/monedas' => [['admin.settings.manage', 'finance.treasury'], 'Monedas y tipo de cambio', 'Administración'],
        'admin/notificaciones' => [['admin.settings.manage'], 'Notificaciones', 'Administración'],
        'admin/integraciones' => [['admin.settings.manage', 'admin.tenant.manage'], 'Integraciones', 'Administración'],
        'admin/auditoria' => [['admin.audit.view'], 'Auditoría', 'Administración'],
        'admin/suscripcion' => [['admin.billing.manage'], 'Suscripción', 'Administración'],
        'admin/api' => [['admin.api.manage'], 'API', 'Administración'],
    ];

    /** Rutas públicas (sitio) que no requieren sesión. */
    public const PUBLIC_PREFIXES = ['sitio'];

    /** Prefijo definido más largo que cubre la ruta, o null. */
    public static function longestPrefix(string $path): ?string
    {
        $best = null;
        foreach (array_keys(self::SCREENS) as $prefix) {
            if (self::matches($path, $prefix) && ($best === null || strlen($prefix) > strlen($best))) {
                $best = $prefix;
            }
        }

        return $best;
    }

    /** @return list<string> */
    public static function required(string $path): array
    {
        $prefix = self::longestPrefix(self::normalize($path));

        return $prefix === null ? [] : self::SCREENS[$prefix][0];
    }

    public static function matches(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix.'/');
    }

    /** `#/crm/leads?x=1` → `crm/leads` */
    public static function normalize(string $path): string
    {
        $path = preg_replace('/[?#].*$/', '', ltrim($path, '#'));

        return trim((string) $path, '/');
    }
}
