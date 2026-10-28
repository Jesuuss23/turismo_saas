<?php

// El navegador nunca llama al API directamente (el BFF de Next lo hace desde el servidor). Solo el widget de la spec 17
// recibe CORS, y únicamente para orígenes verificados por DNS (WG-01): lo resuelven WidgetPreflight y ResolvePublicTenant.
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
