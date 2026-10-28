<?php

namespace App\Domain\Bookings;

use App\Exceptions\ApiException;
use App\Tenancy\TenantContext;

/**
 * `quote_token` (spec 16 §6.1): selección + total firmados con HMAC-SHA256 (clave de la app), TTL 30 min y atado al tenant.
 * El token no es autoridad del precio: al confirmar se recalcula y se compara el total (409 QUOTE_CHANGED).
 */
class QuoteToken
{
    public const TTL_MINUTES = 30;

    public function __construct(private readonly TenantContext $tenant) {}

    public function sign(string $productId, QuoteSelection $sel, QuoteResult $q): string
    {
        $payload = self::b64(json_encode([
            't' => $this->tenant->id(), 'p' => $productId, 's' => $sel->toArray(), 'total' => $q->total, 'cur' => $q->currency,
            'exp' => now()->addMinutes(self::TTL_MINUTES)->getTimestamp(),
        ]));

        return $payload.'.'.self::b64(hash_hmac('sha256', $payload, $this->key(), true));
    }

    /** @return array{product_id: string, sel: QuoteSelection, total: string, currency: string} */
    public function verify(string $token): array
    {
        [$payload, $sig] = array_pad(explode('.', $token, 2), 2, '');
        if ($payload === '' || ! hash_equals(self::b64(hash_hmac('sha256', $payload, $this->key(), true)), $sig)) {
            throw ApiException::unprocessable('QUOTE_TOKEN_INVALID', 'La cotización no es válida. Vuelva a cotizar.');
        }
        $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        if (! is_array($data) || ($data['t'] ?? null) !== $this->tenant->id()) {
            throw ApiException::unprocessable('QUOTE_TOKEN_INVALID', 'La cotización no es válida. Vuelva a cotizar.');
        }
        if (($data['exp'] ?? 0) < now()->getTimestamp()) {
            throw ApiException::unprocessable('QUOTE_TOKEN_EXPIRED', 'La cotización venció (30 minutos). Vuelva a cotizar.');
        }

        return ['product_id' => (string) $data['p'], 'sel' => QuoteSelection::fromArray($data['s']), 'total' => (string) $data['total'], 'currency' => (string) $data['cur']];
    }

    private function key(): string
    {
        return 'quote-token|'.config('app.key');
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
