<?php

namespace App\Services\Integrations;

use App\Exceptions\ApiException;

/**
 * Anti-SSRF de webhooks (spec 13 API-BR-05/06): solo HTTPS; se resuelve el DNS y se rechazan loopback, RFC 1918, link-local,
 * reservados y dominios `.local`/`.internal`. Se revalida en cada envío (DNS rebinding). Los hosts sandbox (`webhook.test`)
 * se simulan sin red para la demo y las pruebas.
 */
class SafeUrlValidator
{
    /** @return string|null motivo del rechazo, o null si la URL es segura */
    public function problem(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (! $parts || ($parts['scheme'] ?? '') === '' || ($parts['host'] ?? '') === '') {
            return 'La URL no es válida.';
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return 'API-BR-05: la URL del webhook debe usar HTTPS.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'La URL no puede incluir credenciales.';
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if (self::isSandbox($host)) {
            return null;
        }
        if ($host === 'localhost' || preg_match('/\.(local|internal|localhost|lan|home|corp)$/', $host)) {
            return 'API-BR-06: la URL apunta a una red interna.';
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);
        if ($ips === []) {
            return "API-BR-06: no se pudo resolver el dominio {$host}.";
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'API-BR-06: la URL apunta a una dirección privada, de loopback o reservada.';
            }
        }

        return null;
    }

    public function assert(string $url, string $field = 'url'): void
    {
        if ($problem = $this->problem($url)) {
            throw ApiException::invalid($field, $problem);
        }
    }

    public static function isSandbox(string $host): bool
    {
        $hosts = array_filter(array_map('trim', (array) config('turismo.webhooks.sandbox_hosts')));
        foreach ($hosts as $sandbox) {
            if ($host === $sandbox || str_ends_with($host, '.'.$sandbox)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    protected function resolve(string $host): array
    {
        $ips = @gethostbynamel($host) ?: [];
        $v6 = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($v6 as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
