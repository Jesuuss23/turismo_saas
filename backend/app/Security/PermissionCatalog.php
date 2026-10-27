<?php

namespace App\Security;

/**
 * Catálogo de permisos: los 57 de spec 00 §7.1 (DB.permissionGroups) más los que agrega cada
 * módulo (MODULE_ADDITIONS). En backend no hay comodines.
 */
final class PermissionCatalog
{
    public const GUARD = 'api';

    /** Permisos agregados por especificaciones de módulo => [roles semilla que lo reciben, origen]. */
    public const MODULE_ADDITIONS = [
        'crm.records.reassign' => [['tenant_admin', 'manager'], '01-crm §11-24'],
        'quotes.reassign' => [['tenant_admin', 'manager'], '03-cotizaciones §11-2, QTE-BR-25'],
        'reservations.reassign' => [['tenant_admin', 'manager'], '04-reservas §6, RES-BR-51'],
    ];

    /** @var array<string, array<string, string>> módulo => [permiso => descripción] */
    public const GROUPS = [
        'Administración' => [
            'admin.tenant.manage' => 'Gestionar tenants y planes de la plataforma',
            'admin.users.manage' => 'Gestionar usuarios del tenant',
            'admin.roles.manage' => 'Gestionar roles y matriz de permisos',
            'admin.settings.manage' => 'Gestionar configuración, notificaciones e integraciones del tenant',
            'admin.audit.view' => 'Ver el log de auditoría',
            'admin.billing.manage' => 'Gestionar la suscripción del tenant',
            'admin.api.manage' => 'Gestionar claves y accesos de API',
        ],
        'Colaboración' => [
            'approvals.view' => 'Ver la bandeja de aprobaciones',
            'inbox.view' => 'Ver la bandeja de entrada omnicanal',
            'inbox.manage' => 'Responder y asignar conversaciones',
        ],
        'CRM' => [
            'crm.leads.view' => 'Ver leads',
            'crm.leads.manage' => 'Crear, editar y asignar leads',
            'crm.opportunities.manage' => 'Gestionar oportunidades',
            'crm.activities.manage' => 'Gestionar actividades comerciales',
            'crm.clients.manage' => 'Gestionar clientes',
            'crm.targets.view' => 'Ver metas comerciales',
            'crm.targets.manage' => 'Definir metas comerciales',
            'crm.claims.manage' => 'Gestionar el libro de reclamaciones',
            'crm.records.reassign' => 'Asignar y reasignar leads, oportunidades, actividades y clientes entre asesores',
        ],
        'Marketing' => [
            'marketing.campaigns.manage' => 'Gestionar campañas de marketing',
        ],
        'Catálogo' => [
            'catalog.products.view' => 'Ver productos del catálogo',
            'catalog.products.create' => 'Crear productos',
            'catalog.products.update' => 'Editar productos y categorías',
            'catalog.products.activate' => 'Activar o desactivar productos',
            'catalog.products.manage_rates' => 'Gestionar tarifas y temporadas',
            'catalog.products.manage_availability' => 'Gestionar disponibilidad y salidas',
            'catalog.products.view_profitability' => 'Ver costos y rentabilidad de productos',
            'catalog.products.publish' => 'Publicar productos en canales',
            'catalog.providers.manage' => 'Gestionar proveedores',
            'catalog.allotments.manage' => 'Gestionar cupos (allotments)',
        ],
        'Cotizaciones' => [
            'quotes.view' => 'Ver cotizaciones',
            'quotes.create' => 'Crear y editar cotizaciones',
            'quotes.approve_internal' => 'Aprobar cotizaciones internamente',
            'quotes.send' => 'Enviar cotizaciones al cliente',
            'quotes.discount_override' => 'Autorizar descuentos fuera de política',
            'quotes.reassign' => 'Reasignar el asesor de una cotización',
        ],
        'Reservas' => [
            'reservations.view' => 'Ver reservas',
            'reservations.create' => 'Crear reservas',
            'reservations.modify' => 'Modificar reservas',
            'reservations.cancel' => 'Cancelar reservas',
            'reservations.reassign' => 'Reasignar el asesor de una reserva',
        ],
        'Operaciones' => [
            'operations.view' => 'Ver órdenes operativas',
            'operations.assign' => 'Asignar guías, conductores y proveedores',
            'operations.vouchers' => 'Emitir vouchers',
            'operations.incidents' => 'Registrar y gestionar incidencias',
            'operations.close' => 'Cerrar servicios operativos',
            'operations.travel_docs' => 'Gestionar documentos de viaje',
        ],
        'Finanzas' => [
            'finance.receivables' => 'Ver y gestionar cuentas por cobrar',
            'finance.payments.register' => 'Registrar pagos de clientes',
            'finance.payments.revert' => 'Revertir pagos',
            'finance.payables' => 'Gestionar cuentas por pagar a proveedores',
            'finance.commissions' => 'Gestionar comisiones',
            'finance.profitability' => 'Ver rentabilidad, costos y márgenes',
            'finance.invoicing' => 'Emitir comprobantes electrónicos',
            'finance.treasury' => 'Gestionar tesorería y caja',
        ],
        'Canales' => [
            'channels.b2b.manage' => 'Gestionar el canal B2B',
            'channels.ecommerce.manage' => 'Gestionar el e-commerce',
            'channels.cms.publish' => 'Publicar contenido del sitio (CMS)',
        ],
        'Reportes' => [
            'reports.view' => 'Ver reportes',
            'reports.export' => 'Exportar reportes y datos',
        ],
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::GROUPS)));
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::names(), true);
    }
}
