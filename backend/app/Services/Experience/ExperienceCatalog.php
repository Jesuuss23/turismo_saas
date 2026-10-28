<?php

namespace App\Services\Experience;

/**
 * Semillas de la spec 17 (centro de reglas v2.2, mensajes al pasajero, monedas, reseñas externas y widget). Los tipos de cambio,
 * proveedores y reseñas son de DEMOSTRACIÓN (decisión del cliente 30/09/2026): se reemplazan sin cambiar el modelo.
 */
final class ExperienceCatalog
{
    /** doc_type => [etiqueta, prefijo, relleno, siguiente]. */
    public const SEQUENCES = [
        'pax_template' => ['Plantilla de mensaje al pasajero', 'PMT-', 3, 11],
        'pax_message' => ['Mensaje al pasajero', 'MSG-', 6, 1],
        'widget_handoff' => ['Traspaso del widget', 'WHO-', 5, 1],
    ];

    /** code => [nombre, símbolo, TC demo en soles por unidad, activa, de cobro]. PEN es la base. */
    public const CURRENCIES = [
        'PEN' => ['Sol peruano', 'S/', '1', true, true],
        'USD' => ['Dólar estadounidense', 'US$', '3.750000', true, true],
        'EUR' => ['Euro', '€', '4.050000', true, false],
        'GBP' => ['Libra esterlina', '£', '4.750000', true, false],
        'BRL' => ['Real brasileño', 'R$', '0.680000', true, false],
        'CLP' => ['Peso chileno', 'CLP$', '0.004000', false, false],
        'COP' => ['Peso colombiano', 'COL$', '0.000920', false, false],
        'ARS' => ['Peso argentino', 'AR$', '0.003900', false, false],
        'MXN' => ['Peso mexicano', 'MX$', '0.200000', false, false],
        'CAD' => ['Dólar canadiense', 'CA$', '2.720000', false, false],
    ];

    /** code => [nombre, tipo, estado, cron, nota]. Solo uno activo como proveedor del tenant (FX-04). */
    public const FX_PROVIDERS = [
        'SIM' => ['Simulador de demostración', 'Simulado', 'activo', '0 8 * * *', 'Genera una variación de ±0,6 % sobre el último valor. No consulta proveedores reales.'],
        'BCRP' => ['BCRP · tipo de cambio interbancario', 'Oficial', 'por_validar', '0 8 * * *', 'Requiere validar el acceso a la serie estadística del BCRP.'],
        'SBS' => ['SBS · tipo de cambio contable', 'Oficial', 'por_validar', '0 9 * * *', 'Publicación diaria de la SBS; pendiente de validar.'],
        'OXR' => ['Open Exchange Rates (API)', 'API', 'inactivo', '0 */6 * * *', 'Proveedor comercial; requiere API key en el vault.'],
        'MANUAL' => ['Ingreso manual', 'Manual', 'activo', '', 'Finanzas registra el tipo de cambio a mano.'],
    ];

    /** provider => [nombre, fase, responde por API, admite invitaciones, alcance]. Fase 1 = priorizados por el cliente (v2.2). */
    public const REVIEW_PROVIDERS = [
        'google' => ['Google Business Profile', 1, true, true, ['reviews.read', 'reviews.reply']],
        'tripadvisor' => ['TripAdvisor', 1, false, true, ['content.read']],
        'trustpilot' => ['Trustpilot', 1, true, true, ['reviews.read', 'reviews.reply', 'invitations.create']],
        'facebook' => ['Facebook', 2, true, false, ['pages_read_user_content', 'pages_manage_engagement']],
        'getyourguide' => ['GetYourGuide', 2, false, false, ['supplier.reviews.read']],
        'viator' => ['Viator', 2, false, false, ['supplier.reviews.read']],
    ];

    public const MESSAGE_EVENTS = [
        'reserva_confirmada' => 'Reserva confirmada', 'saldo_vence' => 'Vencimiento del saldo', 'viaje' => 'Inicio del viaje',
        'fin_viaje' => 'Fin del viaje', 'cambio_horario' => 'Cambio de horario', 'cumpleanos' => 'Cumpleaños del pasajero',
    ];

    public const MESSAGE_CHANNELS = ['email' => 'Email', 'whatsapp' => 'WhatsApp', 'portal' => 'Portal del cliente', 'push' => 'Push (app)'];

    /** MSG-01: variables permitidas en asunto y cuerpo. */
    public const VARIABLES = [
        'pasajero' => 'Nombre del titular', 'reserva' => 'Código de reserva', 'producto' => 'Producto principal', 'fecha_viaje' => 'Fecha de inicio',
        'fecha_fin' => 'Fecha de fin', 'saldo' => 'Saldo pendiente', 'vencimiento_saldo' => 'Vencimiento del saldo', 'punto_recojo' => 'Punto de recojo',
        'agencia' => 'Nombre de la agencia', 'enlace_portal' => 'Enlace al portal', 'enlace_resena' => 'Enlace para reseñar',
    ];

    /** Hora local base de los eventos sin hora (§12 #4). */
    public const EVENT_HOURS = ['saldo_vence' => 9, 'viaje' => 8, 'fin_viaje' => 18, 'cumpleanos' => 8];

    /**
     * 10 plantillas sembradas: code => [nombre, evento, desfase h, desfase de la regla, canales, asunto, cuerpo, idioma, grupo, condiciones].
     */
    public const PAX_TEMPLATES = [
        'PMT-001' => ['Confirmación de reserva', 'reserva_confirmada', 0, false, ['email', 'whatsapp'], 'Su reserva {{reserva}} está confirmada',
            "Hola {{pasajero}}, su reserva {{reserva}} de {{producto}} para el {{fecha_viaje}} está confirmada. Puede ver el detalle en {{enlace_portal}}. — {{agencia}}", 'ES', 'PMT-001', []],
        'PMT-002' => ['Recordatorio de saldo', 'saldo_vence', -72, false, ['email', 'whatsapp'], 'Recordatorio: saldo de su reserva {{reserva}}',
            'Hola {{pasajero}}, le recordamos que el saldo de {{saldo}} de su reserva {{reserva}} vence el {{vencimiento_saldo}}. Puede pagarlo en {{enlace_portal}}.', 'ES', 'PMT-002',
            ['requiresRule' => 'R-DEPOSIT', 'onlyIfBalance' => true]],
        'PMT-003' => ['Datos de acompañantes pendientes', 'reserva_confirmada', 24, false, ['email'], 'Complete los datos de sus acompañantes · {{reserva}}',
            'Hola {{pasajero}}, para emitir sus entradas necesitamos los datos y documentos de todos los pasajeros de la reserva {{reserva}}. Complételos en {{enlace_portal}}.', 'ES', 'PMT-003',
            ['requiresRule' => 'R-COMPANIONS', 'onlyIfMissingDocs' => true]],
        'PMT-004' => ['Recordatorio 48 h antes del viaje', 'viaje', -48, false, ['email', 'whatsapp'], 'Su viaje empieza pronto · {{reserva}}',
            'Hola {{pasajero}}, en dos días empieza {{producto}}. Lo recogeremos en {{punto_recojo}}. Lleve su documento de identidad.', 'ES', 'PMT-004', []],
        'PMT-005' => ['Bienvenida el día del viaje', 'viaje', -2, false, ['whatsapp', 'push'], 'Bienvenido a {{producto}}',
            '¡Buen viaje, {{pasajero}}! Hoy empieza {{producto}}. Su guía lo espera en {{punto_recojo}}.', 'ES', 'PMT-005', []],
        'PMT-006' => ['Gracias por viajar con nosotros', 'fin_viaje', 2, false, ['email'], '¿Cómo estuvo {{producto}}?',
            'Gracias por viajar con {{agencia}}, {{pasajero}}. Cuéntenos cómo le fue en una encuesta de un minuto: {{enlace_portal}}.', 'ES', 'PMT-006', ['requiresRule' => 'R-PAX-FEEDBACK']],
        'PMT-007' => ['Cambio de horario', 'cambio_horario', 0, false, ['whatsapp', 'email'], 'Cambio de horario en su reserva {{reserva}}',
            'Hola {{pasajero}}, hubo un cambio de horario en {{producto}} del {{fecha_viaje}}. Revise el detalle en {{enlace_portal}} o responda este mensaje.', 'ES', 'PMT-007', []],
        'PMT-008' => ['Feliz cumpleaños en ruta', 'cumpleanos', 0, false, ['whatsapp'], 'Feliz cumpleaños',
            '¡Feliz cumpleaños, {{pasajero}}! Todo el equipo de {{agencia}} le desea un gran día en {{producto}}.', 'ES', 'PMT-008', []],
        'PMT-009' => ['Invitación a reseñar', 'fin_viaje', 0, true, ['email'], 'Cuéntenos su experiencia en {{producto}}',
            'Hola {{pasajero}}, ¿nos ayuda con una reseña de su viaje? Su opinión, sea cual sea, ayuda a otros viajeros: {{enlace_resena}}', 'ES', 'PMT-009',
            ['requiresRule' => 'R-REVIEW-INVITE']],
        'PMT-010' => ['Booking confirmation', 'reserva_confirmada', 0, false, ['email', 'whatsapp'], 'Your booking {{reserva}} is confirmed',
            'Hi {{pasajero}}, your booking {{reserva}} for {{producto}} on {{fecha_viaje}} is confirmed. See the details at {{enlace_portal}}. — {{agencia}}', 'EN', 'PMT-001', []],
    ];
}
