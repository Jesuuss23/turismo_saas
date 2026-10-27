<?php

namespace App\Services\Admin;

/**
 * Semillas de Administración (spec 09 §3, §8): planes, entitlements, canales por plan, eventos de notificación
 * con sus plantillas predeterminadas, adapters de integración, jobs del scheduler y valores por defecto de configuración.
 */
final class AdminCatalog
{
    /** §3.1 id => [nombre, rank, precio, usuarios, GB, sedes, soporte, módulos]. */
    public const PLANS = [
        'starter' => ['Starter', 1, '490.00', 5, 5, 1, 'Correo, horario laboral', 6],
        'professional' => ['Professional', 2, '1190.00', 15, 25, 2, 'Correo y chat, 12 h', 12],
        'enterprise' => ['Enterprise', 3, '2890.00', null, 100, null, 'Dedicado 24/7 · SLA 99.9%', 18],
    ];

    /** §3.2 feature => [etiqueta, plan mínimo, valor por plan si no es incluido/no incluido]. */
    public const FEATURES = [
        'crm' => ['CRM: leads, oportunidades y clientes', 'starter'],
        'catalog' => ['Catálogo de productos y tarifas', 'starter'],
        'quotes' => ['Cotizaciones y propuestas', 'starter'],
        'reservations' => ['Reservas', 'starter'],
        'finance_basic' => ['Cobros y facturación básica', 'starter'],
        'portal_client' => ['Portal del cliente', 'starter'],
        'operations' => ['Operaciones y asignaciones', 'professional'],
        'vouchers_rooming' => ['Vouchers y rooming lists', 'professional'],
        'profitability_commissions' => ['Rentabilidad y comisiones', 'professional'],
        'b2b' => ['Canal B2B', 'professional', ['professional' => 'basico', 'enterprise' => 'completo']],
        'reports_export' => ['Reportes y exportación', 'professional'],
        'portal_passenger' => ['Portal del pasajero', 'professional'],
        'multi_sede' => ['Multi-sede', 'enterprise'],
        'ecommerce' => ['E-commerce', 'enterprise'],
        'cms' => ['CMS del sitio', 'enterprise'],
        'mobile_apps' => ['Apps móviles (operador y pasajero)', 'enterprise'],
        'integrations' => ['Integraciones (OSE, pasarela, WhatsApp)', 'enterprise'],
        'audit_advanced' => ['Auditoría avanzada y exportación de bitácora', 'enterprise'],
    ];

    /** §3.3 canal => [etiqueta, plan mínimo, descripción, integración de la que depende]. */
    public const CHANNELS = [
        'portal_cliente' => ['Portal del cliente', 'starter', 'Cotizaciones, viajes, documentos y pagos del cliente', null],
        'portal_pasajero' => ['Portal del pasajero', 'professional', 'Itinerario, vouchers y alertas del viaje', null],
        'b2b' => ['Portal B2B de agencias', 'professional', 'Catálogo con tarifas netas, reservas y crédito', null],
        'whatsapp' => ['WhatsApp Business', 'professional', 'Notificaciones y conversaciones por WhatsApp', 'INT-04'],
        'web' => ['Sitio web', 'enterprise', 'Sitio público con contenido del CMS', null],
        'ecommerce' => ['E-commerce', 'enterprise', 'Venta directa con pago en línea', 'INT-02'],
        'app_operador' => ['App del operador', 'enterprise', 'Guías y conductores en campo', null],
        'app_pasajero' => ['App del pasajero', 'enterprise', 'Viaje en el móvil del pasajero', null],
    ];

    public const BUSINESS_TYPES = ['Agencia de viajes minorista', 'Agencia mayorista', 'Operador turístico', 'DMC / receptivo', 'Agencia corporativa'];

    public const COUNTRIES = ['Perú', 'Bolivia', 'Chile', 'Ecuador', 'Colombia'];

    /** §8.3 código => [etiqueta, E, W, P, Po, destinatarios]. */
    public const EVENTS = [
        'cotizacion.enviada' => ['Cotización enviada', true, true, false, true, 'Cliente'],
        'cotizacion.aprobada' => ['Cotización aprobada', true, false, true, true, 'Vendedor + Gerencia'],
        'reserva.confirmada' => ['Reserva confirmada', true, true, true, true, 'Cliente + Operaciones'],
        'pago.recibido' => ['Pago recibido', true, true, false, true, 'Cliente + Finanzas'],
        'asignacion.creada' => ['Asignación', false, true, true, false, 'Guía / Conductor'],
        'servicio.cambio_horario' => ['Cambio de horario', true, true, true, true, 'Pasajeros + Guía'],
        'incidencia.critica' => ['Incidencia crítica', true, true, true, false, 'Gerencia + Operaciones'],
        'voucher.emitido' => ['Voucher emitido', true, false, true, true, 'Cliente + Proveedor'],
        'servicio.inicio' => ['Inicio/fin de servicio', false, true, true, true, 'Pasajeros'],
        'viaje.cierre' => ['Cierre de viaje', true, false, false, true, 'Cliente (encuesta NPS)'],
    ];

    /** NTF-BR-03: lista cerrada de variables. */
    public const VARIABLES = ['cliente', 'reserva', 'cotizacion', 'fecha_viaje', 'monto', 'servicio', 'hora', 'enlace', 'agencia', 'vendedor'];

    public const TIMINGS = ['immediate' => 'Inmediato', 'batch_15m' => 'Agrupado cada 15 min', 'business_hours' => 'Solo horario laboral (08:00–19:00)'];

    public const CONDITIONS = ['always' => 'Siempre', 'marketing_consent' => 'Solo con consentimiento de marketing', 'amount_gt_5000' => 'Monto mayor a S/ 5 000'];

    /** DEF_TPL: plantilla de correo por evento [asunto, cuerpo]; WhatsApp y push se derivan (§8.3). */
    public const TEMPLATES = [
        'cotizacion.enviada' => ['Su cotización {{cotizacion}} está lista', "Hola {{cliente}},\nle enviamos la cotización {{cotizacion}} para su viaje del {{fecha_viaje}}.\nTotal: {{monto}}.\nRevísela y apruébela aquí: {{enlace}}\n\nSaludos,\n{{vendedor}} · {{agencia}}"],
        'cotizacion.aprobada' => ['Cotización {{cotizacion}} aprobada', "La cotización {{cotizacion}} de {{cliente}} fue aprobada.\nMonto: {{monto}}.\nViaje: {{fecha_viaje}}.\nSiguiente paso: convertirla en reserva.\n\n{{agencia}}"],
        'reserva.confirmada' => ['Reserva {{reserva}} confirmada', "Hola {{cliente}},\nsu reserva {{reserva}} está confirmada.\nInicio del viaje: {{fecha_viaje}}.\nDetalle y documentos: {{enlace}}\n\n{{agencia}}"],
        'pago.recibido' => ['Recibimos su pago de {{monto}}', "Hola {{cliente}},\nregistramos su pago de {{monto}} para la reserva {{reserva}}.\nPuede ver su estado de cuenta en {{enlace}}\n\nGracias,\n{{agencia}}"],
        'asignacion.creada' => ['Nueva asignación: {{servicio}}', "Se le asignó el servicio {{servicio}}.\nFecha: {{fecha_viaje}} a las {{hora}}.\nReserva {{reserva}}.\nConfirme en la app del operador."],
        'servicio.cambio_horario' => ['Cambio de horario: {{servicio}}', "Hola {{cliente}},\nel servicio {{servicio}} cambió de horario.\nNueva hora: {{hora}} del {{fecha_viaje}}.\nDetalle: {{enlace}}\n\n{{agencia}}"],
        'incidencia.critica' => ['Incidencia crítica en {{reserva}}', "Se registró una incidencia crítica en la reserva {{reserva}}.\nServicio: {{servicio}} · {{fecha_viaje}} {{hora}}.\nAtiéndala de inmediato: {{enlace}}"],
        'voucher.emitido' => ['Voucher emitido: {{servicio}}', "Hola {{cliente}},\nemitimos el voucher del servicio {{servicio}} ({{fecha_viaje}}).\nDescárguelo aquí: {{enlace}}\n\n{{agencia}}"],
        'servicio.inicio' => ['Su servicio {{servicio}} inicia', "Hola {{cliente}},\nsu servicio {{servicio}} inicia hoy a las {{hora}}.\nSiga el estado en {{enlace}}\n\n{{agencia}}"],
        'viaje.cierre' => ['¿Cómo fue su viaje?', "Hola {{cliente}},\ngracias por viajar con {{agencia}}.\nCuéntenos su experiencia con la reserva {{reserva}}: {{enlace}}\n\nSu opinión nos ayuda a mejorar."],
    ];

    /** §3.13 código => [nombre, tipo, estado, entorno, nota]. INT-BR-01: sin proveedor contratado quedan “Por validar”. */
    public const ADAPTERS = [
        'INT-01' => ['Facturación electrónica (OSE / SUNAT)', 'fiscal', 'por_validar', 'sandbox', 'Proveedor OSE pendiente de contrato del cliente (spec 09 §12-10).'],
        'INT-02' => ['Pasarela de pagos', 'payments', 'por_validar', 'sandbox', 'Pasarela por definir: los pagos web quedan pendientes de validación.'],
        'INT-03' => ['Correo transaccional', 'email', 'activo', 'production', null],
        'INT-04' => ['WhatsApp Business (Meta)', 'messaging', 'sandbox', 'sandbox', 'Requiere plantillas aprobadas por Meta para pasar a producción.'],
        'INT-05' => ['Mapas y geolocalización', 'maps', 'parcial', 'production', 'Solo geocodificación de puntos de encuentro.'],
        'INT-06' => ['Adapter tren (Ferrovía Sur Andina)', 'suppliers', 'activo', 'production', null],
        'INT-07' => ['Distribución (GDS / OTA)', 'distribution', 'futuro', 'sandbox', 'Fuera del alcance actual.'],
    ];

    public const ADAPTER_STATUS = ['activo' => 'Activo', 'por_validar' => 'Por validar', 'sandbox' => 'Sandbox', 'parcial' => 'Parcial', 'futuro' => 'Futuro'];

    /** §8.2 código => [nombre, cron, etiqueta, cola, comando artisan (null = propio de Administración)]. */
    public const JOBS = [
        'JOB-01' => ['Expirar holds de disponibilidad', '*/5 * * * *', 'cada 5 min', 'critical', ['catalog:expire-holds']],
        'JOB-02' => ['Marcar cotizaciones vencidas', '5 0 * * *', 'diario 00:05', 'default', ['quotes:expire']],
        'JOB-03' => ['Recordatorios de pago', '0 9 * * *', 'diario 09:00', 'notifications', ['fin:run', ['job' => 'payment-reminders']]],
        'JOB-04' => ['Recordatorio de actividades CRM', '0 * * * *', 'cada hora', 'notifications', ['crm:activities-remind']],
        'JOB-05' => ['Notificar próximos viajes', '0 5 * * *', 'diario 05:00', 'notifications', ['ops:monitor', ['mode' => 'daily']]],
        'JOB-06' => ['Detectar reservas pendientes de proveedor', '0 */2 * * *', 'cada 2 h', 'default', null],
        'JOB-07' => ['Sincronizar proveedores (adapters)', '0 */6 * * *', 'cada 6 h', 'integrations', null],
        'JOB-08' => ['Reportes programados (gerencia)', '0 7 * * 1', 'lunes 07:00', 'reports', null],
        'JOB-09' => ['Expirar asignaciones no confirmadas', '*/30 * * * *', 'cada 30 min', 'default', ['ops:monitor', ['mode' => 'watch']]],
    ];

    /** Secciones de configuración y sus valores por defecto (§3.8). */
    public const SETTINGS = [
        'general' => [
            'email' => null, 'phone' => null, 'web' => null, 'address' => null, 'legal_rep' => null, 'mincetur_registry' => null,
            'lang' => 'es-PE', 'date_format' => 'DD/MM/AAAA', 'main_sede_id' => null,
        ],
        'currency' => [
            'alt_currency' => 'USD', 'price_with_tax' => true, 'fx_rate' => '3.750000', 'fx_source' => 'Manual diario (SBS referencial)',
            'tax_pct' => '18', 'rounding' => '0.01', 'round_mode' => 'nearest',
        ],
        'policies' => [
            'quote_validity_days' => 10, 'hold_minutes' => 30, 'markup_pct' => '25', 'max_discount_pct' => '5', 'min_margin_pct' => '15',
            'advance_pct' => '40', 'balance_days_before' => 15,
            'cancel_text' => 'Cancelaciones con más de 30 días: sin penalidad. Entre 30 y 15 días: 30 %. Menos de 15 días: 100 %.',
            'modify_text' => 'Los cambios de fecha están sujetos a disponibilidad y diferencia de tarifa.',
            'noshow_text' => 'La no presentación se cobra al 100 %.',
            'refund_text' => 'Las devoluciones se procesan en un plazo de 15 días hábiles.',
        ],
        'operations' => [
            'voucher_hours_before' => 72, 'assign_expiry_min' => 120, 'checkin_tolerance_min' => 15, 'modify_min_hours' => 48, 'max_pax_per_guide' => 16,
            'offline_sync_min' => 10, 'pay_reminder_days' => 3, 'trip_notice_hours' => 48, 'incident_escalate_min' => 30,
        ],
        'channels' => [
            'portal_cliente' => true, 'portal_pasajero' => true, 'b2b' => true, 'whatsapp' => false, 'web' => true, 'ecommerce' => true,
            'app_operador' => true, 'app_pasajero' => true,
        ],
    ];

    public const FX_SOURCES = ['Manual diario (SBS referencial)', 'Manual semanal (tesorería)', 'Automático SBS (integración por definir)'];

    /** CFG-BR-08: tipos que se pueden agregar como nueva serie. */
    public const NEW_SERIES = [
        'credit_note' => ['Nota de crédito', 'FC01-', 8], 'debit_note' => ['Nota de débito', 'FD01-', 8], 'commission_settlement' => ['Liquidación de comisión', 'LQC-', 5],
        'cash_receipt' => ['Recibo de caja', 'RC-', 6], 'purchase_order' => ['Orden de compra a proveedor', 'OC-', 5],
    ];

    /** Correlativos que muestra Configuración › Series (documentos visibles al cliente o fiscales). */
    public const VISIBLE_SERIES = ['quote', 'reservation', 'ops_order', 'voucher', 'invoice', 'receipt', 'credit_note', 'debit_note', 'receivable', 'payment',
        'payable', 'commission', 'commission_settlement', 'cash_receipt', 'purchase_order', 'ecom_order', 'b2b_settlement'];

    /** Etiqueta de un plan para mensajes. */
    public static function planName(string $id): string
    {
        return self::PLANS[$id][0] ?? $id;
    }

    /** WhatsApp = primeras 4 líneas sin vacías; push = cuerpo en una línea recortado a 140 (§8.3). */
    public static function derive(string $channel, string $subject, string $body): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $body)), fn ($l) => $l !== ''));

        return match ($channel) {
            'whatsapp' => [$subject, implode("\n", array_slice($lines, 0, 4))],
            'push' => [$subject, mb_substr(implode(' ', $lines), 0, 140)],
            default => [$subject, $body],
        };
    }
}
