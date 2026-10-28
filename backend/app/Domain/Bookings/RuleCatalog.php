<?php

namespace App\Domain\Bookings;

/**
 * Catálogo de plataforma de las 31 reglas del centro (spec 16 §3.1, `DB.settings.bookingRules`): se copia a
 * `platform_booking_rule_templates` y de ahí a `sys_booking_rules` de cada tenant. `override` = el producto puede
 * desactivarla o cambiar parámetros (CAT-RL-01); las reglas solo-tenant llevan `false`.
 */
final class RuleCatalog
{
    public const GROUPS = ['Disponibilidad', 'Pasajeros', 'Alojamiento', 'Adicionales', 'Precios', 'Monedas y pagos', 'Checkout', 'Comunicación'];

    public const SCOPES = ['Sitio web', 'Widget', 'Cotizaciones', 'B2B', 'Portal cliente', 'Operaciones'];

    private const WEB = ['Sitio web', 'Widget', 'B2B'];

    private const ALL = ['Sitio web', 'Widget', 'Cotizaciones', 'B2B', 'Portal cliente'];

    /** rule_key => [grupo, nombre, descripción, activa, params, canales, override de producto]. */
    public const RULES = [
        'R-AVAIL-RANGES' => ['Disponibilidad', 'Disponibilidad por rangos de fechas', 'Los productos se venden según rangos (disponible, cerrado, agotado) con cupos por día; un cierre puede superponerse a un rango disponible como excepción.', true, [], self::ALL, true],
        'R-CUTOFF' => ['Disponibilidad', 'Anticipación mínima de compra', 'Horas mínimas entre la compra y el inicio del servicio. El producto puede fijar sus propias horas.', true, ['defaultHours' => 24], self::ALL, true],
        'R-WEEKDAYS' => ['Disponibilidad', 'Días de operación', 'Solo se venden los días de la semana en que opera el producto.', true, [], self::ALL, true],
        'R-HOLD' => ['Checkout', 'Retención de cupos en el checkout', 'Minutos que se retienen los cupos mientras el cliente paga.', true, ['minutes' => 30], self::WEB, false],
        'R-MIN-GROUP' => ['Disponibilidad', 'Grupo mínimo para operar', 'Si no se alcanza el mínimo de pasajeros a N días de la salida, Operaciones propone reprogramar.', true, ['minPax' => 2, 'decisionDays' => 7], ['Operaciones'], true],
        'R-MIN-ADULTS' => ['Pasajeros', 'Mínimo de adultos por reserva', 'Toda reserva debe incluir al menos este número de adultos.', true, ['min' => 1], self::ALL, true],
        'R-CHILD-PER-ADULT' => ['Pasajeros', 'Niños por adulto', 'Máximo de niños por adulto y banda de edad del niño; el niño paga un % del adulto o la tarifa Niño.', true, ['maxPerAdult' => 2, 'childMinAge' => 3, 'childMaxAge' => 11, 'childPricePct' => 75], self::ALL, true],
        'R-INFANT-FREE' => ['Pasajeros', 'Infantes sin costo', 'Los infantes no pagan; máximo de infantes por adulto.', true, ['maxPerAdult' => 1, 'infantMaxAge' => 2], self::ALL, true],
        'R-MAX-PAX' => ['Pasajeros', 'Máximo de pasajeros por reserva', 'El total de pasajeros no puede superar el máximo de la ficha del producto.', true, [], self::ALL, true],
        'R-COMPANIONS' => ['Checkout', 'Datos de acompañantes', 'El checkout pide los datos de cada acompañante.', true, [], self::WEB, true],
        'R-ALTITUDE' => ['Checkout', 'Declaración de salud por altura', 'Productos sobre cierta altitud piden una declaración de salud.', false, ['minAltitude' => 3500], self::WEB, true],
        'R-NOMINATIVE' => ['Checkout', 'Reserva nominativa', 'Los nombres y documentos de los pasajeros no se pueden cambiar después de confirmar.', true, [], self::ALL, true],
        'R-LODGING' => ['Alojamiento', 'Alojamiento opcional por ciudad', 'El cliente elige hotel y habitaciones por tramo del circuito.', true, [], self::ALL, true],
        'R-LODGING-CAPACITY' => ['Alojamiento', 'Capacidad de habitaciones', 'Las camas elegidas por tramo deben alojar a adultos y niños (los infantes comparten).', true, [], self::ALL, true],
        'R-SINGLE-SUPP' => ['Alojamiento', 'Suplemento por habitación simple', 'Recargo porcentual sobre las habitaciones simples.', false, ['pct' => 20], self::ALL, true],
        'R-ADDONS' => ['Adicionales', 'Adicionales opcionales', 'Museos, entradas, comidas y extensiones que el cliente puede sumar.', true, [], self::ALL, true],
        'R-PROMO' => ['Precios', 'Códigos promocionales', 'Aplica códigos de promoción vigentes del producto.', true, [], self::WEB, false],
        'R-SEASON' => ['Precios', 'Temporadas', 'El precio base se ajusta por el factor de la temporada de la fecha.', true, [], self::ALL, false],
        'R-MULTICURRENCY' => ['Monedas y pagos', 'Multimoneda', 'El cliente ve y paga en PEN o USD con el tipo de cambio del día más un margen.', true, ['fxMarginPct' => 0], self::WEB, false],
        'R-PREVIEW-REMOVE' => ['Checkout', 'Quitar ítems en la vista previa', 'El cliente puede quitar adicionales y alojamiento antes de pagar.', true, [], self::WEB, false],
        'R-PAY-FEE' => ['Monedas y pagos', 'Recargo por pago con tarjeta', 'Recargo porcentual al pagar con tarjeta.', false, ['pct' => 3.5], self::WEB, false],
        'R-GROUP-DISC' => ['Precios', 'Descuento por grupo', 'Descuento sobre el subtotal desde cierto número de pasajeros que pagan.', false, ['fromPax' => 6, 'pct' => 5], self::ALL, true],
        'R-EARLY-BIRD' => ['Precios', 'Compra anticipada', 'Descuento si se compra con N días o más de anticipación.', false, ['days' => 60, 'pct' => 5], self::ALL, true],
        'R-LAST-MINUTE' => ['Precios', 'Recargo de último minuto', 'Recargo si se compra con menos de N días de anticipación.', false, ['days' => 3, 'pct' => 10], self::ALL, true],
        'R-DEPOSIT' => ['Monedas y pagos', 'Pago con adelanto', 'Permite pagar un % de adelanto y el saldo N días antes del viaje.', true, ['pct' => 30, 'balanceDueDays' => 30], self::ALL, true],
        'R-PAY-LATER' => ['Monedas y pagos', 'Reservar y pagar después', 'Reserva sin pago con los cupos retenidos N horas.', false, ['holdHours' => 24], self::WEB, true],
        'R-PICKUP' => ['Checkout', 'Punto de recojo', 'El checkout pide el punto de recojo (y el vuelo si corresponde).', true, [], self::ALL, true],
        'R-COMMENTS' => ['Checkout', 'Comentarios del cliente', 'Campo libre de comentarios y pedidos especiales.', true, [], self::WEB, true],
        'R-PAX-MESSAGES' => ['Comunicación', 'Mensajes al pasajero', 'Recordatorios antes del viaje por email y WhatsApp.', true, [], ['Portal cliente', 'Operaciones'], false],
        'R-PAX-FEEDBACK' => ['Comunicación', 'Encuesta posterior al viaje', 'Encuesta de satisfacción al terminar el servicio.', true, [], ['Portal cliente'], false],
        'R-REVIEW-INVITE' => ['Comunicación', 'Invitación a reseñar', 'Invita a dejar una reseña pública después del viaje.', false, [], ['Portal cliente'], false],
    ];

    /** Reglas que el motor evalúa (todas las del catálogo); las agregadas por el tenant solo si `RuleRegistry` tiene handler. */
    public static function keys(): array
    {
        return array_keys(self::RULES);
    }

    /** JSON Schema de los parámetros (CAT-RL-03): números ≥ 0 con máximo según el nombre del parámetro. */
    public static function schema(array $params): array
    {
        $properties = [];
        foreach ($params as $name => $value) {
            $properties[$name] = ['type' => is_int($value) ? 'integer' : 'number', 'minimum' => 0, 'maximum' => self::maxFor($name)];
        }

        return ['type' => 'object', 'additionalProperties' => false, 'properties' => (object) $properties];
    }

    public static function maxFor(string $param): int
    {
        return match (true) {
            str_contains(strtolower($param), 'pct') => 100,
            str_ends_with($param, 'Hours') => 8760,
            $param === 'minutes' => 1440,
            str_ends_with(strtolower($param), 'days') => 365,
            str_ends_with($param, 'Age') => 120,
            $param === 'minAltitude' => 6000,
            default => 100,
        };
    }

    /** Fila de plantilla de plataforma (sin tenant). */
    public static function templates(): array
    {
        $rows = [];
        $i = 0;
        foreach (self::RULES as $key => [$group, $name, $description, $enabled, $params, $scope, $override]) {
            $rows[] = [
                'rule_key' => $key, 'group_name' => $group, 'name' => $name, 'description' => $description, 'enabled' => $enabled,
                'params' => json_encode((object) $params), 'scope' => json_encode($scope), 'product_override' => $override,
                'params_schema' => json_encode(self::schema($params)), 'sort_order' => ++$i,
            ];
        }

        return $rows;
    }
}
