<?php

namespace App\Services\Integrations;

use App\Services\Platform\PlatformCatalog;

/**
 * OpenAPI 3.1 de la API pública (spec 13 §6 `/api/v1/docs/openapi.json`). Se arma a mano desde el catálogo de scopes y eventos
 * (equivalente a lo que generaría dedoc/scramble para las rutas `/ext/*`).
 */
final class OpenApiDocument
{
    public static function build(string $serverUrl): array
    {
        $page = ['type' => 'object', 'properties' => ['data' => ['type' => 'array'], 'meta' => ['$ref' => '#/components/schemas/PageMeta']]];
        $op = fn (string $summary, string $scope, array $params = [], ?string $schema = null, int $ok = 200, ?array $body = null) => array_filter([
            'summary' => $summary, 'security' => [['apiKey' => []]], 'x-scope' => $scope,
            'parameters' => $params ?: null,
            'requestBody' => $body ? ['required' => true, 'content' => ['application/json' => ['schema' => $body]]] : null,
            'responses' => [
                (string) $ok => ['description' => 'OK', 'headers' => self::rateHeaders(), 'content' => ['application/json' => ['schema' => $schema ? ['$ref' => "#/components/schemas/{$schema}"] : $page]]],
                '401' => ['$ref' => '#/components/responses/Unauthorized'], '403' => ['$ref' => '#/components/responses/Forbidden'],
                '429' => ['$ref' => '#/components/responses/TooManyRequests'],
            ],
        ]);
        $query = fn (string $name, string $description) => ['name' => $name, 'in' => 'query', 'required' => false, 'description' => $description, 'schema' => ['type' => 'string']];

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Qhapaq Cloud · API pública del tenant', 'version' => PlatformCatalog::API_VERSION,
                'description' => 'Autenticación con API key (Authorization: Bearer qts_live_…). La key identifica al tenant, no a un usuario. '
                    .'Rate limit por key y cuota mensual según el plan (headers X-RateLimit-* y X-Quota-*). Webhooks firmados con HMAC-SHA256 (header Qhapaq-Signature).'],
            'servers' => [['url' => rtrim($serverUrl, '/').'/api/v1/ext']],
            'paths' => [
                '/reservations' => ['get' => $op('Listar reservas', 'reservations:read', [$query('status', 'Estado'), $query('from', 'Viaje desde (YYYY-MM-DD)'), $query('to', 'Viaje hasta'), $query('page', 'Página')])],
                '/reservations/{code}' => ['get' => $op('Detalle de una reserva', 'reservations:read', [['name' => 'code', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], 'Reservation')],
                '/products' => ['get' => $op('Catálogo publicado', 'catalog:read', [$query('q', 'Búsqueda por nombre'), $query('page', 'Página')])],
                '/clients' => ['get' => $op('Listar clientes', 'clients:read', [$query('q', 'Búsqueda por nombre'), $query('page', 'Página')])],
                '/leads' => ['post' => $op('Crear un lead', 'leads:write', [], 'Lead', 201, ['$ref' => '#/components/schemas/LeadInput'])],
                '/webhooks' => ['get' => $op('Listar webhooks', 'webhooks:manage'), 'post' => $op('Crear un webhook', 'webhooks:manage', [], 'Webhook', 201, ['$ref' => '#/components/schemas/WebhookInput'])],
            ],
            'components' => [
                'securitySchemes' => ['apiKey' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'qts_{env}_{32 base62}']],
                'schemas' => [
                    'PageMeta' => ['type' => 'object', 'properties' => ['page' => ['type' => 'integer'], 'per_page' => ['type' => 'integer'], 'total' => ['type' => 'integer'], 'last_page' => ['type' => 'integer']]],
                    'Reservation' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string', 'example' => 'RES-2026-000354'], 'title' => ['type' => 'string'], 'status' => ['type' => 'string'],
                        'travel_from' => ['type' => 'string', 'format' => 'date'], 'travel_to' => ['type' => 'string', 'format' => 'date'], 'currency' => ['type' => 'string'], 'total' => ['type' => 'number'],
                        'client' => ['type' => ['object', 'null'], 'properties' => ['code' => ['type' => 'string'], 'name' => ['type' => 'string']]]]],
                    'LeadInput' => ['type' => 'object', 'required' => ['full_name'], 'properties' => ['full_name' => ['type' => 'string'], 'email' => ['type' => 'string', 'format' => 'email'],
                        'phone' => ['type' => 'string'], 'interest_code' => ['type' => 'string'], 'destination' => ['type' => 'string'], 'travel_date' => ['type' => 'string', 'format' => 'date'],
                        'pax' => ['type' => 'integer'], 'notes' => ['type' => 'string']]],
                    'Lead' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string'], 'full_name' => ['type' => 'string'], 'status' => ['type' => 'string']]],
                    'WebhookInput' => ['type' => 'object', 'required' => ['url', 'events'], 'properties' => ['url' => ['type' => 'string', 'format' => 'uri', 'pattern' => '^https://'],
                        'events' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(PlatformCatalog::WEBHOOK_EVENTS)]], 'description' => ['type' => 'string']]],
                    'Webhook' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string'], 'url' => ['type' => 'string'], 'events' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'secret' => ['type' => 'string', 'description' => 'Solo en la respuesta 201']]],
                    'WebhookEvent' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'example' => 'evt_01j…'], 'type' => ['type' => 'string', 'enum' => array_keys(PlatformCatalog::WEBHOOK_EVENTS)],
                        'api_version' => ['type' => 'string', 'example' => PlatformCatalog::API_VERSION], 'created_at' => ['type' => 'string', 'format' => 'date-time'],
                        'tenant_id' => ['type' => 'string', 'format' => 'uuid'], 'data' => ['type' => 'object']]],
                    'Error' => ['type' => 'object', 'properties' => ['error' => ['type' => 'string'], 'code' => ['type' => 'string'], 'message' => ['type' => 'string']]],
                ],
                'responses' => [
                    'Unauthorized' => ['description' => 'API key inválida, vencida o revocada (API_KEY_INVALID)', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                    'Forbidden' => ['description' => 'Sin scope (INSUFFICIENT_SCOPE), IP fuera de la allowlist (IP_NOT_ALLOWED) o plan sin API (FEATURE_NOT_IN_PLAN)',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                    'TooManyRequests' => ['description' => 'RATE_LIMITED o QUOTA_EXCEEDED', 'headers' => ['Retry-After' => ['schema' => ['type' => 'integer']]],
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                ],
            ],
            'webhooks' => collect(PlatformCatalog::WEBHOOK_EVENTS)->mapWithKeys(fn ($label, $event) => [$event => ['post' => ['summary' => $label,
                'parameters' => [['name' => 'Qhapaq-Signature', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 't=…,v1=hex(HMAC_SHA256(secret, t + "." + body))']],
                'requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/WebhookEvent']]]],
                'responses' => ['200' => ['description' => 'Recibido (2xx en menos de 10 s)']]]]])->all(),
            'x-scopes' => PlatformCatalog::SCOPES,
        ];
    }

    private static function rateHeaders(): array
    {
        return collect(['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset', 'X-Quota-Limit', 'X-Quota-Remaining'])
            ->mapWithKeys(fn ($h) => [$h => ['schema' => ['type' => 'integer']]])->all();
    }
}
