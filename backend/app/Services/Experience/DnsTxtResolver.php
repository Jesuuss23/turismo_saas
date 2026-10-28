<?php

namespace App\Services\Experience;

/**
 * Resolución de registros TXT para verificar dominios del widget (WG-01). En sandbox (`turismo.widget_dns_sandbox`, por defecto
 * fuera de producción) se simula que el cliente publicó el token, salvo dominios que empiezan por `sin-txt.` (para probar el fallo).
 */
class DnsTxtResolver
{
    /** @return list<string> */
    public function txt(string $host, string $expected): array
    {
        if (config('turismo.widget_dns_sandbox', ! app()->isProduction())) {
            return str_contains($host, '.sin-txt.') || str_starts_with(substr($host, strlen('_qhapaq.')), 'sin-txt.') ? [] : [$expected];
        }
        $records = @dns_get_record($host, DNS_TXT) ?: [];

        return array_values(array_map(fn ($r) => (string) ($r['txt'] ?? ''), $records));
    }
}
