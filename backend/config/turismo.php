<?php

return [

    // Roles de PostgreSQL usados por las políticas RLS y los GRANT de las migraciones.
    'db_roles' => [
        'app' => env('DB_USERNAME', 'turismo_app'),
        'platform' => env('DB_PLATFORM_USERNAME', 'turismo_platform'),
    ],

    // Dominio base de los subdominios de tenant: {slug}.{base_domain} (SEC-BR-02).
    'tenant_base_domain' => env('TENANT_BASE_DOMAIN', 'turismo.test'),

    // Expiración de tokens por experiencia, en minutos (§3.4).
    'token_ttl' => [
        'backoffice' => 12 * 60,
        'portal' => 7 * 24 * 60,
        'mobile' => 30 * 24 * 60,
        'platform' => 12 * 60,
    ],

    // Back-office: expiración deslizante; se renueva como máximo cada N minutos.
    'token_sliding_refresh' => 5,

    'idempotency_ttl_hours' => 24,

    // SEC-BR-25: solicitudes por minuto y usuario (spec 00 §12-12: ajustar con volumen real).
    // Cada pantalla del CRM hace varias llamadas en paralelo; en local/e2e conviene subirlo.
    'api_rate_per_minute' => (int) env('API_RATE_PER_MINUTE', 120),

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    // Spec 08 §12-11: pasarela sandbox hasta definir INT-02; el webhook firma el cuerpo con HMAC-SHA256.
    'payments' => [
        'webhook_secret' => env('PAYMENTS_WEBHOOK_SECRET', 'sandbox-webhook-secret'),
    ],

    // Spec 13: pasarela de la plataforma en sandbox (INT-02 por definir) y OSE simulado; PLATFORM_OSE_DOWN=true simula la caída del OSE.
    'platform' => [
        'ose_down' => (bool) env('PLATFORM_OSE_DOWN', false),
    ],

    // Spec 13 §8.4: dominios cuyo receptor de webhooks se simula (respuesta por ruta: /fail → 500, /gone → 410, /slow → timeout).
    'webhooks' => [
        'sandbox_hosts' => array_filter(explode(',', (string) env('WEBHOOK_SANDBOX_HOSTS', 'webhook.test,hooks.sandbox.test'))),
        'timeout' => 10,
    ],

    'audit_retention_years' => 5,

    // Spec 09 §4.5: números registrados en el sandbox de WhatsApp (separados por coma). Vacío = sin restricción.
    'whatsapp_sandbox_numbers' => env('WHATSAPP_SANDBOX_NUMBERS') ? array_map('trim', explode(',', env('WHATSAPP_SANDBOX_NUMBERS'))) : null,

    // Spec 09 §3.16, EXP-BR-02: disco de exportaciones (S3 en producción) y días de disponibilidad.
    'exports_disk' => env('EXPORTS_DISK', 'local'),
    'exports_ttl_days' => 7,

    // Operaciones (spec 05): disco de evidencias (S3 en producción)
    // y Expo Push (sin token de acceso, el canal push queda en sandbox: registra el envío).
    'evidence_disk' => env('OPS_EVIDENCE_DISK', 'local'),
    'expo_access_token' => env('EXPO_ACCESS_TOKEN'),

];
