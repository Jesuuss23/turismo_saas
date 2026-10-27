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

    'audit_retention_years' => 5,

    // Operaciones (spec 05): disco de evidencias (S3 en producción)
    // y Expo Push (sin token de acceso, el canal push queda en sandbox: registra el envío).
    'evidence_disk' => env('OPS_EVIDENCE_DISK', 'local'),
    'expo_access_token' => env('EXPO_ACCESS_TOKEN'),

];
