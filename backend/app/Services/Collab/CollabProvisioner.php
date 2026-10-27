<?php

namespace App\Services\Collab;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/** Configuración inicial de Colaboración por tenant (spec 10 §3, §11): correlativos, matriz de reglas, cuentas de canal y plantillas. */
class CollabProvisioner
{
    /** doc_type => [etiqueta, prefijo, relleno, siguiente, reinicio anual]. */
    public const SEQUENCES = [
        'approval_request' => ['Solicitud de aprobación', 'APR-{Y}-', 4, 1, true],
        'approval_rule' => ['Regla de aprobación', 'RA-', 2, 15, false], // RA-01…RA-14 = matriz por defecto
        'conversation' => ['Conversación', 'CNV-', 4, 101, false],
        'reply_template' => ['Plantilla de respuesta', 'PLT-', 2, 5, false],
    ];

    public static function seedTenant(string $tenantId, ConnectionInterface $db): void
    {
        $now = now();
        foreach (self::SEQUENCES as $docType => [$label, $prefix, $pad, $next, $yearReset]) {
            $db->table('sys_sequences')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'doc_type' => $docType, 'label' => $label, 'series' => 'default',
                'prefix' => str_replace('{Y}', (string) $now->year, $prefix), 'pad_width' => $pad, 'next_number' => $next, 'year_reset' => $yearReset, 'updated_at' => $now,
            ]);
        }
        if (! $db->table('collab_approval_rules')->where('tenant_id', $tenantId)->exists()) {
            self::seedRules($tenantId, $db);
        }
        foreach (CollabCatalog::FIRST_RESPONSE_SLA as $channel => $minutes) {
            $db->table('collab_channel_accounts')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'channel' => $channel, 'name' => CollabCatalog::CHANNELS[$channel],
                'first_response_sla_minutes' => $minutes, 'status' => 'sandbox', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach (CollabCatalog::TEMPLATES as [$code, $name, $body, $channels, $approved, $waName]) {
            $db->table('collab_reply_templates')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'body' => $body, 'channels' => json_encode($channels),
                'is_whatsapp_approved' => $approved, 'whatsapp_template_name' => $waName, 'whatsapp_language' => $waName ? 'es_PE' : null,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /** Matriz por defecto (también «Restaurar por defecto»): RA-01… con el nivel 1 de los tipos de origen bloqueado. */
    public static function seedRules(string $tenantId, ConnectionInterface $db, ?callable $code = null): void
    {
        $now = now();
        $n = 1;
        foreach (CollabCatalog::DEFAULT_RULES as [$type, $level, $permission, $min, $sla]) {
            $db->table('collab_approval_rules')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'code' => $code ? $code() : sprintf('RA-%02d', $n++), 'request_type' => $type, 'level' => $level,
                'permission' => $permission, 'min_amount_pen' => $min, 'sla_hours' => $sla, 'forbid_self_approval' => true, 'is_active' => true,
                'is_origin_locked' => $level === 1 && isset(CollabCatalog::ORIGIN_PERMISSIONS[$type]), 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
