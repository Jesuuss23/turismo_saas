<?php

namespace App\Services\Crm;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Semilla CRM de un tenant (spec 01 §3.1, §3.6 y códigos de §3): catálogos configurables,
 * etapas del pipeline y correlativos. Idempotente; corre con una conexión con permisos de INSERT
 * (alta de tenant o migración).
 */
final class CrmProvisioner
{
    /** kind => [code => label]. Los códigos son estables: la lógica depende de algunos (referido, otro…). */
    public const CATALOGS = [
        'lead_source' => [
            'formulario_web' => 'Formulario web', 'whatsapp' => 'WhatsApp', 'facebook_ads' => 'Facebook Ads',
            'instagram' => 'Instagram', 'referido' => 'Referido', 'feria_turismo' => 'Feria Turismo',
            'llamada_entrante' => 'Llamada entrante', 'b2b' => 'B2B', 'email' => 'Email', 'visita_oficina' => 'Visita en oficina',
        ],
        'lead_channel' => [
            'web' => 'Web', 'whatsapp' => 'WhatsApp', 'facebook' => 'Facebook', 'instagram' => 'Instagram',
            'telefono' => 'Teléfono', 'presencial' => 'Presencial', 'email' => 'Email',
        ],
        'lead_interest' => [
            'paquete' => 'Paquete', 'tour' => 'Tour', 'excursion' => 'Excursión', 'corporativo' => 'Viaje corporativo',
            'promocion' => 'Viaje de promoción', 'luna_miel' => 'Luna de miel', 'alojamiento' => 'Alojamiento', 'alianza' => 'Alianza',
        ],
        'client_segment' => ['retail' => 'Retail', 'corporativo' => 'Corporativo', 'vip' => 'VIP', 'grupos' => 'Grupos', 'b2b' => 'B2B'],
        'lost_reason' => [
            'precio' => 'Precio', 'competencia' => 'Eligió a la competencia', 'sin_presupuesto' => 'Sin presupuesto',
            'cambio_planes' => 'Cambio de planes del cliente', 'sin_respuesta' => 'Sin respuesta del cliente',
            'sin_disponibilidad' => 'Producto o fecha sin disponibilidad', 'otro' => 'Otro',
        ],
        'discard_reason' => [
            'no_potencial' => 'No es cliente potencial (proveedor / alianza)', 'datos_invalidos' => 'Datos de contacto inválidos',
            'sin_interes' => 'Sin interés real', 'fuera_cobertura' => 'Fuera de cobertura / destino no ofrecido',
            'spam' => 'Spam o prueba', 'otro' => 'Otro',
        ],
        'case_type' => ['reclamo' => 'Reclamo', 'queja' => 'Queja', 'sugerencia' => 'Sugerencia', 'felicitacion' => 'Felicitación'],
        'survey_channel' => [
            'llamada' => 'Llamada', 'whatsapp' => 'WhatsApp', 'email' => 'Email', 'web' => 'Encuesta web', 'portal' => 'Portal del cliente',
        ],
        'activity_type' => [
            'llamada' => 'Llamada', 'email' => 'Email', 'whatsapp' => 'WhatsApp', 'reunion' => 'Reunión', 'tarea' => 'Tarea', 'postventa' => 'Postventa',
        ],
    ];

    /** Entradas que exigen detalle (CRM-BR-24). */
    public const REQUIRES_DETAIL = ['lost_reason' => ['otro']];

    /** code => [name, default_probability, is_closed] en orden. */
    public const STAGES = [
        'calificacion' => ['Calificación', 20, false],
        'descubrimiento' => ['Descubrimiento', 35, false],
        'cotizacion' => ['Cotización', 55, false],
        'negociacion' => ['Negociación', 70, false],
        'ganada' => ['Ganada', 100, true],
        'perdida' => ['Perdida', 0, true],
    ];

    /** doc_type => [label, prefix, next]. Formato del prototipo: prefijo + 4 dígitos, sin año (§12-5). */
    public const SEQUENCES = [
        'lead' => ['Lead', 'LEAD-', 1],
        'opportunity' => ['Oportunidad', 'OPP-', 1],
        'activity' => ['Actividad', 'ACT-', 1],
        'client' => ['Cliente', 'CLI-', 1],
        'survey' => ['Encuesta NPS', 'ENC-', 1],
        'case' => ['Caso de postventa', 'REC-', 1],
    ];

    public static function seedTenant(string $tenantId, ConnectionInterface $db): void
    {
        $now = now();

        foreach (self::CATALOGS as $kind => $items) {
            $order = 0;
            foreach ($items as $code => $label) {
                $db->table('crm_catalog_items')->insertOrIgnore([
                    'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'kind' => $kind, 'code' => $code, 'label' => $label,
                    'requires_detail' => in_array($code, self::REQUIRES_DETAIL[$kind] ?? [], true),
                    'sort_order' => ++$order, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        $order = 0;
        foreach (self::STAGES as $code => [$name, $probability, $closed]) {
            $db->table('crm_pipeline_stages')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'code' => $code, 'name' => $name,
                'default_probability' => $probability, 'sort_order' => ++$order, 'is_closed' => $closed,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (self::SEQUENCES as $docType => [$label, $prefix, $next]) {
            $db->table('sys_sequences')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'doc_type' => $docType, 'label' => $label,
                'series' => 'default', 'prefix' => $prefix, 'pad_width' => 4, 'next_number' => $next, 'year_reset' => false,
                'updated_at' => $now,
            ]);
        }
    }
}
