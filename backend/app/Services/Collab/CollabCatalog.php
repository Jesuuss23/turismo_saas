<?php

namespace App\Services\Collab;

/** Catálogos de Colaboración (spec 10): tipos de solicitud, matriz por defecto (§11), canales y SLA de 1.ª respuesta (INB-BR-01). */
final class CollabCatalog
{
    public const TYPES = [
        'quote' => 'Cotización fuera de política', 'cancel' => 'Anulación de reserva', 'reversal' => 'Reversión de pago',
        'payable' => 'Cuenta por pagar', 'commission' => 'Comisión', 'provider_rate' => 'Tarifa de proveedor',
        'credit_b2b' => 'Crédito B2B', 'refund' => 'Reembolso', 'other' => 'Otra excepción',
    ];

    /** Creados desde la bandeja con «Nueva solicitud». */
    public const GENERIC = ['credit_b2b', 'refund', 'other'];

    /** El solicitante puede retirarlas (§4.1). */
    public const WITHDRAWABLE = ['quote', 'cancel', 'reversal', 'credit_b2b', 'refund', 'other'];

    /** CxP/comisión rechazada = observada; se reenvía en una nueva ronda. */
    public const RESUBMITTABLE = ['payable' => 'finance.payables', 'commission' => 'finance.commissions'];

    /** APR-BR-02: permiso de nivel 1 fijado por el módulo de origen. */
    public const ORIGIN_PERMISSIONS = [
        'quote' => 'quotes.approve_internal', 'cancel' => 'reservations.cancel', 'reversal' => 'finance.payments.revert',
        'payable' => 'finance.payables', 'commission' => 'finance.commissions', 'provider_rate' => 'catalog.providers.manage',
    ];

    /**
     * §11 matriz por defecto: [tipo, nivel, permiso, monto desde (S/), SLA horas]. Solo el SLA de cotización (8 h) viene de la spec;
     * el resto son valores iniciales editables (§12-1).
     */
    public const DEFAULT_RULES = [
        ['quote', 1, 'quotes.approve_internal', 0, 8],
        ['cancel', 1, 'reservations.cancel', 0, 24],
        ['cancel', 2, 'finance.payments.revert', 20000, 24],
        ['reversal', 1, 'finance.payments.revert', 0, 24],
        ['reversal', 2, 'quotes.approve_internal', 10000, 24],
        ['payable', 1, 'finance.payables', 0, 48],
        ['payable', 2, 'finance.profitability', 10000, 48],
        ['commission', 1, 'finance.commissions', 0, 72],
        ['provider_rate', 1, 'catalog.providers.manage', 0, 72],
        ['credit_b2b', 1, 'quotes.approve_internal', 0, 24],
        ['credit_b2b', 2, 'finance.receivables', 5000, 24],
        ['refund', 1, 'quotes.approve_internal', 0, 24],
        ['refund', 2, 'finance.payments.revert', 1000, 24],
        ['other', 1, 'quotes.approve_internal', 0, 48],
    ];

    /** Morph del registro de origen → tabla (§3.2). */
    public const SUBJECTS = [
        'quote' => 'quote_versions', 'cancel' => 'booking_reservations', 'reversal' => 'fin_payments', 'payable' => 'fin_payables',
        'commission' => 'fin_commissions', 'provider_rate' => 'supplier_rate_requests', 'credit_b2b' => 'channel_b2b_agencies', 'refund' => 'booking_reservations',
    ];

    public const STATUS_LABELS = ['pendiente' => 'Pendiente', 'info' => 'Información solicitada', 'aprobada' => 'Aprobada', 'rechazada' => 'Rechazada', 'anulada' => 'Anulada'];

    public const EVENT_LABELS = [
        'creada' => 'Solicitud creada', 'aprobada_nivel' => 'Nivel aprobado', 'aprobada' => 'Aprobada', 'rechazada' => 'Rechazada',
        'info_solicitada' => 'Información solicitada', 'info_respondida' => 'Información respondida', 'delegada' => 'Delegada', 'retirada' => 'Retirada',
        'reenviada' => 'Reenviada', 'cerrada_en_origen' => 'Cerrada en el módulo de origen', 'sla_advertencia' => 'SLA al 75 %', 'sla_vencido' => 'SLA vencido',
    ];

    /** Efecto visible al aprobar (§4.3). */
    public const EFFECTS = [
        'quote' => 'Registra la aprobación interna de la versión y habilita su envío al cliente.',
        'cancel' => 'Cancela la reserva (PROC-028): libera cupos, anula servicios y vouchers, cancela CxC abiertas y genera penalidad, nota de crédito y reembolso.',
        'reversal' => 'Revierte el pago, recalcula la CxC y devuelve el crédito B2B si corresponde.',
        'payable' => 'Aprueba la cuenta por pagar y habilita su programación.',
        'commission' => 'Pasa la comisión de calculada a aprobada.',
        'provider_rate' => 'Crea la nueva vigencia del costo; si la fecha ya empezó se aplica de inmediato. Notifica al proveedor.',
        'credit_b2b' => 'Amplía la línea de crédito de la agencia y la reactiva si estaba bloqueada por consumo.',
        'refund' => 'Crea un pago de reembolso pendiente para que Finanzas lo procese.',
        'other' => 'Solo registro y evidencia.',
    ];

    public const CHANNELS = ['whatsapp' => 'WhatsApp', 'email' => 'Email', 'webchat' => 'Chat web', 'form' => 'Formulario', 'portal' => 'Portal'];

    /** INB-BR-01 (minutos). */
    public const FIRST_RESPONSE_SLA = ['webchat' => 15, 'whatsapp' => 30, 'form' => 120, 'email' => 240, 'portal' => 240];

    public const CONVERSATION_STATUS = ['abierta' => 'Abierta', 'pendiente' => 'Pendiente (espera cliente)', 'resuelta' => 'Resuelta'];

    public const PRIORITIES = ['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta', 'critica' => 'Crítica'];

    /** §3.9 variables permitidas en plantillas. */
    public const TEMPLATE_VARIABLES = ['nombre', 'asesor', 'empresa', 'cotizacion', 'reserva', 'telefono_emergencia'];

    /** INB-BR-11 */
    public const ATTACHMENT_MIMES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'text/plain',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];

    public const ATTACHMENT_MAX_BYTES = 16777216;

    /** Plantillas iniciales [código, nombre, cuerpo, canales, aprobada por Meta, nombre en Meta]. */
    public const TEMPLATES = [
        ['PLT-01', 'Saludo inicial', 'Hola {{nombre}}, soy {{asesor}} de {{empresa}}. Gracias por escribirnos, ¿en qué podemos ayudarle?', ['whatsapp', 'email', 'webchat', 'form', 'portal'], true, 'saludo_inicial'],
        ['PLT-02', 'Envío de cotización', 'Hola {{nombre}}, le compartimos la cotización {{cotizacion}}. Quedo atento(a) a sus comentarios. {{asesor}}', ['whatsapp', 'email', 'webchat'], true, 'envio_cotizacion'],
        ['PLT-03', 'Seguimiento de reserva', 'Hola {{nombre}}, su reserva {{reserva}} está en curso. Ante cualquier emergencia llame al {{telefono_emergencia}}.', ['whatsapp', 'email', 'portal'], true, 'seguimiento_reserva'],
        ['PLT-04', 'Cierre de atención', 'Gracias por contactar a {{empresa}}, {{nombre}}. Damos por resuelta su consulta; si necesita algo más, responda a este mensaje.', ['email', 'webchat', 'form', 'portal'], false, null],
    ];
}
