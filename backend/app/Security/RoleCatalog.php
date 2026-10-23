<?php

namespace App\Security;

/**
 * Roles semilla y matriz Permiso × Rol (§7.2, §7.3), plantilla “Estándar”.
 */
final class RoleCatalog
{
    public const SUPER_ADMIN = 'super_admin';

    /** Roles que no ven el back-office (SEC-BR-05). */
    public const EXTERNAL = ['guide', 'driver', 'b2b', 'customer', 'passenger', 'provider'];

    /** Permisos fijos del usuario de plataforma (no se guardan en spatie: guard distinto). */
    public const SUPER_ADMIN_PERMISSIONS = ['admin.tenant.manage', 'admin.audit.view'];

    /**
     * Los 12 roles de tenant que se copian en el alta (PROC-001).
     *
     * @return array<string, array{display_name: string, description: string, scope: string, experience: string}>
     */
    public static function tenantRoles(): array
    {
        return [
            'tenant_admin' => ['display_name' => 'Tenant Admin', 'description' => 'Administración completa del tenant', 'scope' => 'tenant', 'experience' => 'backoffice'],
            'manager' => ['display_name' => 'Manager', 'description' => 'Supervisión, aprobaciones y reportes', 'scope' => 'tenant', 'experience' => 'backoffice'],
            'sales' => ['display_name' => 'Ventas (Sales)', 'description' => 'CRM, clientes y cotizaciones', 'scope' => 'tenant', 'experience' => 'backoffice'],
            'operations' => ['display_name' => 'Operaciones', 'description' => 'Reservas, proveedores y ejecución', 'scope' => 'tenant', 'experience' => 'backoffice'],
            'finance' => ['display_name' => 'Finanzas', 'description' => 'Cobros, pagos, costos y rentabilidad', 'scope' => 'tenant', 'experience' => 'backoffice'],
            'accounting' => ['display_name' => 'Contabilidad', 'description' => 'Facturación y procesos contables', 'scope' => 'tenant', 'experience' => 'backoffice'],
            'guide' => ['display_name' => 'Guía', 'description' => 'Ejecución de servicios (app operador)', 'scope' => 'mobile', 'experience' => 'mobile'],
            'driver' => ['display_name' => 'Conductor', 'description' => 'Transporte (app operador)', 'scope' => 'mobile', 'experience' => 'mobile'],
            'b2b' => ['display_name' => 'Usuario B2B', 'description' => 'Agencia asociada — canal B2B', 'scope' => 'canal', 'experience' => 'portal'],
            'customer' => ['display_name' => 'Cliente', 'description' => 'Portal del cliente', 'scope' => 'canal', 'experience' => 'portal'],
            'passenger' => ['display_name' => 'Pasajero', 'description' => 'Portal / app del pasajero', 'scope' => 'canal', 'experience' => 'portal'],
            'provider' => ['display_name' => 'Proveedor', 'description' => 'Confirmación de solicitudes', 'scope' => 'canal', 'experience' => 'portal'],
        ];
    }

    /**
     * Matriz semilla. Tenant Admin y Manager se construyen por exclusión, como en data.js.
     *
     * @return array<string, list<string>>
     */
    public static function matrix(): array
    {
        $all = PermissionCatalog::names();

        $managerExcluded = [
            'admin.tenant.manage', 'admin.roles.manage', 'admin.settings.manage', 'admin.users.manage',
            'admin.billing.manage', 'admin.api.manage', 'finance.payments.register', 'finance.payments.revert',
            'finance.invoicing', 'catalog.providers.manage',
        ];

        return [
            'tenant_admin' => array_values(array_diff($all, ['admin.tenant.manage'])),
            'manager' => array_values(array_diff($all, $managerExcluded)),
            'sales' => [
                'crm.leads.view', 'crm.leads.manage', 'crm.opportunities.manage', 'crm.activities.manage', 'crm.clients.manage',
                'catalog.products.view', 'quotes.view', 'quotes.create', 'quotes.send', 'reservations.view', 'reservations.create',
                'reports.view', 'approvals.view', 'inbox.view', 'inbox.manage', 'crm.targets.view',
            ],
            'operations' => [
                'catalog.products.view', 'catalog.products.manage_availability', 'catalog.providers.manage', 'reservations.view',
                'reservations.modify', 'operations.view', 'operations.assign', 'operations.vouchers', 'operations.incidents',
                'operations.close', 'reports.view', 'approvals.view', 'inbox.view', 'inbox.manage', 'catalog.allotments.manage',
                'operations.travel_docs',
            ],
            'finance' => [
                'finance.receivables', 'finance.payments.register', 'finance.payments.revert', 'finance.payables', 'finance.commissions',
                'finance.profitability', 'finance.invoicing', 'reservations.view', 'quotes.view', 'catalog.products.view',
                'catalog.products.view_profitability', 'reports.view', 'reports.export', 'finance.treasury', 'approvals.view',
            ],
            'accounting' => ['finance.invoicing', 'finance.receivables', 'reservations.view', 'reports.view', 'reports.export', 'finance.treasury'],
            'guide' => ['operations.view', 'operations.incidents'],
            'driver' => ['operations.view', 'operations.incidents'],
            'b2b' => ['catalog.products.view', 'quotes.create', 'reservations.create', 'reservations.view'],
            'customer' => ['quotes.view', 'reservations.view'],
            'passenger' => ['reservations.view'],
            'provider' => ['operations.view'],
        ];
    }

    public static function isExternal(string $role): bool
    {
        return in_array($role, self::EXTERNAL, true);
    }
}
