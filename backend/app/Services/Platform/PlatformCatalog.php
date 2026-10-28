<?php

namespace App\Services\Platform;

use App\Domain\Bookings\RuleCatalog;

/**
 * Semillas de la plataforma SaaS (spec 13 §3): métricas y límites por plan, add-ons, metadatos de entitlements,
 * scopes de la API pública, eventos de webhook, política de dunning, emisor y la matriz demo Planes × reglas (v2.2).
 */
final class PlatformCatalog
{
    /** Conexión de las tablas de plataforma (rol PG de plataforma, sin RLS). */
    public const CONNECTION = 'pgsql_platform';

    /** §3.3 métrica => [etiqueta, unidad, tipo (gauge = snapshot, counter = acumulador mensual), hard por defecto]. */
    public const METRICS = [
        'users' => ['Usuarios', 'usuarios', 'gauge', true],
        'sedes' => ['Sedes', 'sedes', 'gauge', true],
        'storage_gb' => ['Almacenamiento', 'GB', 'gauge', true],
        'reservations_month' => ['Reservas del mes', 'reservas', 'counter', false],
        'whatsapp_month' => ['Mensajes de WhatsApp', 'mensajes', 'counter', true],
        'email_month' => ['Correos', 'correos', 'counter', true],
        'api_calls_month' => ['Llamadas a la API', 'llamadas', 'counter', true],
        'api_rate_per_min' => ['Rate limit de la API', 'req/min', 'rate', true],
        'webhooks' => ['Webhooks', 'webhooks', 'gauge', true],
        'api_keys' => ['API keys activas', 'keys', 'gauge', true],
    ];

    /** Métricas que se muestran como medidores de consumo (el rate es un parámetro, no un consumo). */
    public const METERED = ['users', 'sedes', 'storage_gb', 'reservations_month', 'whatsapp_month', 'email_month', 'api_calls_month', 'webhooks', 'api_keys'];

    /** Métricas del historial mensual (§2 «Consumo mensual»). */
    public const HISTORY = ['whatsapp_month', 'email_month', 'api_calls_month', 'reservations_month', 'storage_gb'];

    /** §3.3 valores semilla: plan => métrica => valor (null = ilimitado, 0 = no incluido). */
    public const LIMITS = [
        'starter' => ['users' => 5, 'sedes' => 1, 'storage_gb' => 5, 'reservations_month' => 150, 'whatsapp_month' => 500, 'email_month' => 5000,
            'api_calls_month' => 0, 'api_rate_per_min' => 0, 'webhooks' => 0, 'api_keys' => 0],
        'professional' => ['users' => 15, 'sedes' => 2, 'storage_gb' => 25, 'reservations_month' => 600, 'whatsapp_month' => 2000, 'email_month' => 20000,
            'api_calls_month' => 100000, 'api_rate_per_min' => 60, 'webhooks' => 3, 'api_keys' => 5],
        'enterprise' => ['users' => null, 'sedes' => null, 'storage_gb' => 100, 'reservations_month' => 3000, 'whatsapp_month' => 5000, 'email_month' => 100000,
            'api_calls_month' => 1000000, 'api_rate_per_min' => 600, 'webhooks' => 20, 'api_keys' => 25],
    ];

    /** §3.2 precio anual y días de prueba por plan. */
    public const PLAN_EXTRA = [
        'starter' => ['price_year' => '4900.00', 'trial_days' => 14],
        'professional' => ['price_year' => '11900.00', 'trial_days' => 14],
        'enterprise' => ['price_year' => '28900.00', 'trial_days' => 14],
    ];

    /** §3.4 feature => [módulo, tipo, add-on que la otorga, prefijos de API que protege EnsureFeature]. */
    public const FEATURE_META = [
        'crm' => ['CRM', 'module', null, []],
        'catalog' => ['Catálogo', 'module', null, []],
        'quotes' => ['Ventas', 'module', null, []],
        'reservations' => ['Ventas', 'module', null, []],
        'finance_basic' => ['Finanzas', 'module', null, []],
        'portal_client' => ['Canales', 'module', null, []],
        'operations' => ['Operaciones', 'module', null, []],
        'vouchers_rooming' => ['Operaciones', 'module', null, []],
        'profitability_commissions' => ['Finanzas', 'module', null, []],
        'b2b' => ['Canales', 'module', 'b2b_full', ['channels/b2b']],
        'reports_export' => ['Análisis', 'module', null, []],
        'portal_passenger' => ['Canales', 'module', null, []],
        'multi_sede' => ['Administración', 'limit', null, []],
        'ecommerce' => ['Canales', 'module', null, ['channels/ecommerce']],
        'cms' => ['Canales', 'module', null, ['cms/contents']],
        'mobile_apps' => ['Operaciones', 'addon', 'mobile_apps', []],
        'integrations' => ['Administración', 'module', null, []],
        'audit_advanced' => ['Administración', 'module', null, []],
        'travel_docs' => ['Operaciones', 'module', null, []],
        'treasury' => ['Finanzas', 'module', null, ['treasury']],
        'inbox_omnichannel' => ['Colaboración', 'module', null, []],
        'marketing_campaigns' => ['Marketing', 'module', null, ['marketing']],
        'api_access' => ['API pública', 'module', null, ['integrations/api-keys', 'ext']],
        'webhooks' => ['API pública', 'module', null, ['integrations/webhooks', 'integrations/webhook-deliveries']],
        'oauth_apps' => ['API pública', 'module', null, ['integrations/oauth-apps']],
        'custom_domain' => ['Administración', 'addon', 'custom_domain', []],
        'ai_quote_assistant' => ['Ventas', 'beta', null, []],
    ];

    /** Pantallas del back-office que se ocultan del menú si el plan no incluye la feature (§7, capa 2: UX, no seguridad). */
    public const SCREEN_FEATURES = [
        'finanzas/tesoreria' => 'treasury',
        'canales/b2b' => 'b2b',
        'canales/ecommerce' => 'ecommerce',
        'canales/cms' => 'cms',
        'operaciones/documentos-viaje' => 'travel_docs',
        'inbox' => 'inbox_omnichannel',
        'marketing' => 'marketing_campaigns',
        'marketing/campanas' => 'marketing_campaigns',
    ];

    /**
     * §3.5 add-on => [etiqueta, unidad, cantidad ajustable, precio mensual, planes donde se contrata, planes donde viene incluido, grants].
     * `extra_users` tiene precio por plan (addon_prices).
     */
    public const ADDONS = [
        'extra_users' => ['Usuarios adicionales', 'usuario', true, '59.00', ['starter', 'professional'], [], ['users' => 1]],
        'whatsapp_pack' => ['Paquete WhatsApp +1 000', 'paquete', true, '120.00', ['starter', 'professional', 'enterprise'], [], ['whatsapp_month' => 1000]],
        'storage_pack' => ['Almacenamiento +50 GB', 'paquete', true, '90.00', ['starter', 'professional', 'enterprise'], [], ['storage_gb' => 50]],
        'b2b_full' => ['Canal B2B completo', 'módulo', false, '390.00', ['professional'], ['enterprise'], ['feature' => 'b2b', 'value' => 'completo']],
        'mobile_apps' => ['Apps móviles (operador y pasajero)', 'módulo', false, '490.00', ['professional'], ['enterprise'], ['feature' => 'mobile_apps']],
        'custom_domain' => ['Dominio propio', 'dominio', false, '49.00', ['professional', 'enterprise'], [], ['feature' => 'custom_domain']],
    ];

    /** Precio de `extra_users` por plan. */
    public const ADDON_PRICES = ['extra_users' => ['starter' => '59.00', 'professional' => '49.00']];

    /** §3.11 scopes de la API pública. */
    public const SCOPES = [
        'reservations:read' => 'Leer reservas',
        'catalog:read' => 'Leer el catálogo publicado',
        'clients:read' => 'Leer clientes',
        'leads:write' => 'Crear leads',
        'webhooks:manage' => 'Gestionar webhooks',
    ];

    /** §8.4 eventos de webhook (payload sin costos ni márgenes). */
    public const WEBHOOK_EVENTS = [
        'quote.sent' => 'Cotización enviada',
        'quote.approved' => 'Cotización aprobada',
        'reservation.created' => 'Reserva creada',
        'reservation.confirmed' => 'Reserva confirmada',
        'reservation.cancelled' => 'Reserva cancelada',
        'payment.applied' => 'Pago aplicado',
        'payment.reversed' => 'Pago revertido',
        'invoice.issued' => 'Comprobante emitido',
        'voucher.issued' => 'Voucher emitido',
        'incident.created' => 'Incidencia registrada',
        'incident.resolved' => 'Incidencia resuelta',
        'lead.created' => 'Lead creado',
        // Spec 17 §8: widget, reseñas externas y mensajes al pasajero.
        'widget.checkout_started' => 'Traspaso del widget al checkout',
        'review.received' => 'Reseña externa recibida',
        'passenger.message.failed' => 'Mensaje al pasajero fallido',
    ];

    /** Versión de payload que se fija al crear un webhook. */
    public const API_VERSION = '2026-09-01';

    /** §8.4 backoff de reintentos de entrega (segundos) y fallos consecutivos que deshabilitan el webhook (API-BR-07). */
    public const WEBHOOK_BACKOFF = [60, 300, 1800, 7200, 21600, 86400];

    public const WEBHOOK_MAX_FAILURES = 10;

    /** §4.3 política de dunning por defecto. */
    public const DUNNING = ['retry_days' => [1, 3, 7], 'suspend_after_last' => true, 'retry_hour' => 10];

    /** §8.1 emisor de los comprobantes de la plataforma. */
    public const ISSUER = [
        'ruc' => '20612345678', 'name' => 'Qhapaq Cloud S.A.C.', 'address' => 'Av. Javier Prado Este 492, San Isidro, Lima', 'igv_pct' => '18.00',
        'invoice_series' => 'FQ01', 'credit_series' => 'NQ01', 'email' => 'facturacion@qhapaq.cloud', 'ops_email' => 'ops@qhapaq.cloud',
    ];

    public const SUBSCRIPTION_STATUS = [
        'trialing' => 'En prueba', 'active' => 'Activa', 'past_due' => 'Pago pendiente', 'cancel_scheduled' => 'Cancelación programada',
        'suspended' => 'Suspendida', 'canceled' => 'Cancelada',
    ];

    public const INVOICE_STATUS = ['draft' => 'Borrador', 'open' => 'Pendiente', 'paid' => 'Pagada', 'failed' => 'Fallida', 'uncollectible' => 'Vencida', 'void' => 'Anulada'];

    public const SUNAT_STATUS = ['pending' => 'Pendiente', 'accepted' => 'Aceptada', 'observed' => 'Observada', 'rejected' => 'Rechazada'];

    public const CYCLES = ['monthly' => 'Mensual', 'annual' => 'Anual'];

    /** SUB-BR-11: motivos de la encuesta de cancelación. */
    public const CANCEL_REASONS = [
        'price' => 'Precio', 'features' => 'Faltan funcionalidades', 'competitor' => 'Me voy a otro proveedor', 'business_closed' => 'Cierro o pauso el negocio',
        'support' => 'Soporte', 'other' => 'Otro motivo',
    ];

    /** Códigos de rechazo de la pasarela sandbox por los últimos 4 dígitos de la tarjeta. */
    public const SANDBOX_DECLINES = [
        '0002' => ['card_declined', 'Tarjeta rechazada por el banco emisor'],
        '9995' => ['insufficient_funds', 'Fondos insuficientes'],
        '0069' => ['expired_card', 'Tarjeta vencida'],
    ];

    /** v2.2 grupos del centro de reglas de reserva. */
    /** v2.2 grupos de la matriz planes × reglas: los 8 grupos del centro de reglas (spec 16/17). */
    public const RULE_GROUPS = [
        'disponibilidad' => 'Disponibilidad', 'pasajeros' => 'Pasajeros', 'alojamiento' => 'Alojamiento', 'adicionales' => 'Adicionales',
        'precios' => 'Precios', 'monedas_pagos' => 'Monedas y pagos', 'checkout' => 'Checkout', 'comunicacion' => 'Comunicación',
    ];

    /**
     * v2.2 matriz demo (decisión del cliente 30/09/2026): código R-* => [grupo, etiqueta, plan mínimo]. Starter 15, Professional +14,
     * Enterprise +3. Sale del catálogo del motor (`RuleCatalog`) para que la matriz y el centro de reglas usen los mismos códigos.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function bookingRules(): array
    {
        $slugs = array_flip(self::RULE_GROUPS);
        $out = [];
        foreach (RuleCatalog::RULES as $code => [$group, $name]) {
            $out[$code] = [$slugs[$group], $name, RuleCatalog::MIN_PLAN[$code]];
        }

        return $out;
    }

    public static function metricLabel(string $metric): string
    {
        return self::METRICS[$metric][0] ?? $metric;
    }
}
