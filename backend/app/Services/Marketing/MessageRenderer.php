<?php

namespace App\Services\Marketing;

use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Variables de plantilla (§3.5) y enlace de baja firmado (MKT-BR-02). El token lleva tenant, titular, canal y campaña; se firma con la
 * clave de la app, así la página pública de baja no requiere sesión.
 */
class MessageRenderer
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function render(?string $text, array $contact, string $channel, ?string $promoCode = null, ?string $campaignId = null): string
    {
        if ($text === null) {
            return '';
        }
        $vars = [
            'nombre' => explode(' ', trim($contact['name']))[0] ?: $contact['name'],
            'destino' => $contact['destination'] ?: 'Perú',
            'asesor' => $contact['owner'] ?: 'nuestro equipo',
            'codigo_promo' => $promoCode ?: '—',
            'fecha_viaje' => $contact['next_trip'] ? Carbon::parse($contact['next_trip'])->format('d/m/Y') : 'por definir',
            'agencia' => $this->tenant->tenant()?->name ?? 'la agencia',
            'enlace_baja' => $this->unsubscribeUrl($contact['type'], $contact['id'], $channel, $campaignId),
        ];

        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', fn ($m) => $vars[$m[1]] ?? $m[0], $text);
    }

    public function unsubscribeUrl(string $type, string $id, string $channel, ?string $campaignId = null): string
    {
        return rtrim((string) config('turismo.frontend_url'), '/').'/sitio/baja/'.$this->token($type, $id, $channel, $campaignId);
    }

    public function token(string $type, string $id, string $channel, ?string $campaignId = null): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode(['t' => $this->tenant->id(), 's' => $type, 'i' => $id, 'c' => $channel, 'm' => $campaignId])), '+/', '-_'), '=');

        return $payload.'.'.substr(hash_hmac('sha256', $payload, (string) config('app.key')), 0, 32);
    }

    /** @return array{t: string, s: string, i: string, c: string, m: ?string}|null */
    public static function decode(string $token): ?array
    {
        [$payload, $sig] = array_pad(explode('.', $token, 2), 2, '');
        if ($payload === '' || ! hash_equals(substr(hash_hmac('sha256', $payload, (string) config('app.key')), 0, 32), $sig)) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);

        return is_array($data) && isset($data['t'], $data['s'], $data['i'], $data['c']) ? $data + ['m' => null] : null;
    }
}
