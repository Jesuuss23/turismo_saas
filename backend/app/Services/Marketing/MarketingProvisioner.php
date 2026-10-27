<?php

namespace App\Services\Marketing;

use App\Services\Admin\AdminProvisioner;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Configuración inicial de marketing y metas por tenant (spec 14): correlativos, plantillas base de las 6 automatizaciones
 * (pausadas; las de WhatsApp quedan en revisión de Meta, MKT-BR-03), política de bonos y JOB-16..19.
 */
class MarketingProvisioner
{
    /** doc_type => [etiqueta, prefijo, relleno, siguiente]. */
    public const SEQUENCES = [
        'mkt_subscriber' => ['Suscriptor', 'SUB-', 4, 1],
        'mkt_consent' => ['Consentimiento', 'CNS-', 4, 1],
        'mkt_segment' => ['Segmento', 'SEG-', 3, 1],
        'mkt_template' => ['Plantilla de marketing', 'TPL-', 2, 1],
        'mkt_campaign' => ['Campaña', 'CMP-', 4, 1],
        'sales_team' => ['Equipo de ventas', 'EQ-', 2, 1],
        'sales_target' => ['Meta comercial', 'MET-', 4, 1],
    ];

    public const JOBS = ['JOB-16', 'JOB-17', 'JOB-18', 'JOB-19'];

    /** key => [nombre, disparador, espera, canal, finalidad, plantilla [nombre, categoría, asunto, cuerpo]] (§3.11, §8 RunAutomations). */
    public const AUTOMATIONS = [
        'welcome' => ['Bienvenida al suscriptor', 'Nuevo suscriptor del newsletter (≤ 7 días)', 'Inmediato', 'email', 'comercial',
            ['Bienvenida al newsletter', 'MARKETING', '¡Bienvenido a {{agencia}}, {{nombre}}!',
                "Hola {{nombre}}:\n\nGracias por suscribirte. Te enviaremos ideas de viaje a {{destino}} y ofertas exclusivas.\n\nSi no deseas recibir más correos: {{enlace_baja}}"]],
        'cart' => ['Carrito abandonado', 'Pedido web en pago pendiente por más de 1 h', '1 hora', 'whatsapp', 'comercial',
            ['carrito_abandonado', 'MARKETING', null, 'Hola {{nombre}}, tu reserva a {{destino}} quedó pendiente de pago. ¿Te ayudamos a completarla? Responde BAJA para no recibir más mensajes.']],
        'pretrip' => ['Recordatorio pre-viaje', 'Reserva confirmada con inicio en ≤ 3 días', '3 días antes del viaje', 'whatsapp', 'servicio',
            ['recordatorio_previaje', 'UTILITY', null, 'Hola {{nombre}}, tu viaje a {{destino}} empieza el {{fecha_viaje}}. Tu asesor {{asesor}} está atento a cualquier consulta. Responde BAJA para no recibir más mensajes.']],
        'survey' => ['Encuesta post-viaje (NPS)', 'Reserva completada (≤ 7 días) sin encuesta', 'Al completar el viaje', 'email', 'servicio',
            ['Encuesta post-viaje', 'SERVICIO', '¿Cómo te fue en {{destino}}, {{nombre}}?',
                "Hola {{nombre}}:\n\nCuéntanos del 0 al 10 qué tan probable es que nos recomiendes. Tu opinión nos ayuda a mejorar.\n\nPara dejar de recibir correos: {{enlace_baja}}"]],
        'birthday' => ['Saludo de cumpleaños', 'Cumpleaños del cliente en los próximos 7 días', '7 días antes', 'email', 'comercial',
            ['Feliz cumpleaños', 'MARKETING', '¡Feliz cumpleaños, {{nombre}}!',
                "Hola {{nombre}}:\n\nPor tu cumpleaños te regalamos un descuento con el código {{codigo_promo}}.\n\nSi no deseas recibir más correos: {{enlace_baja}}"]],
        'reactivation' => ['Reactivación de clientes', 'Cliente sin compras en 365 días', 'Lunes 08:00', 'email', 'comercial',
            ['Te extrañamos', 'MARKETING', '{{nombre}}, ¿planeamos tu próximo viaje?',
                "Hola {{nombre}}:\n\nHace un tiempo que no viajamos juntos. {{asesor}} tiene nuevas propuestas para ti.\n\nSi no deseas recibir más correos: {{enlace_baja}}"]],
    ];

    public static function seedTenant(string $tenantId, ConnectionInterface $db): void
    {
        $now = now();
        foreach (self::SEQUENCES as $docType => [$label, $prefix, $pad, $next]) {
            $db->table('sys_sequences')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'doc_type' => $docType, 'label' => $label, 'series' => 'default',
                'prefix' => $prefix, 'pad_width' => $pad, 'next_number' => $next, 'year_reset' => false, 'updated_at' => $now,
            ]);
        }
        $db->table('crm_sales_target_policies')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'created_at' => $now, 'updated_at' => $now]);
        if (! $db->table('mkt_automations')->where('tenant_id', $tenantId)->exists()) {
            $n = 0;
            foreach (self::AUTOMATIONS as $key => [$name, $trigger, $delay, $channel, $purpose, [$tplName, $category, $subject, $body]]) {
                $n++;
                $templateId = (string) Str::uuid7();
                $db->table('mkt_templates')->insert(['id' => $templateId, 'tenant_id' => $tenantId, 'code' => sprintf('TPL-%02d', $n), 'name' => $tplName,
                    'channel' => $channel, 'category' => $category, 'status' => $channel === 'email' ? 'activa' : 'en_revision', 'subject' => $subject, 'body' => $body,
                    'provider_template_id' => $channel === 'whatsapp' ? "wa_tpl_{$tplName}" : null, 'created_at' => $now, 'updated_at' => $now]);
                $db->table('mkt_automations')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'code' => sprintf('AUT-%02d', $n), 'key' => $key,
                    'name' => $name, 'trigger_desc' => $trigger, 'delay_desc' => $delay, 'channel' => $channel, 'template_id' => $templateId, 'purpose' => $purpose,
                    'status' => 'pausada', 'created_at' => $now, 'updated_at' => $now]);
            }
            $db->table('sys_sequences')->where('tenant_id', $tenantId)->where('doc_type', 'mkt_template')->update(['next_number' => $n + 1]);
        }
        AdminProvisioner::ensureSystemJobs($tenantId, $db, self::JOBS, $now);
    }
}
