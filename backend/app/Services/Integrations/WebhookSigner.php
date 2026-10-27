<?php

namespace App\Services\Integrations;

/**
 * Firma HMAC-SHA256 de webhooks (spec 13 §8.4): `t = unix_time`, `v1 = hex(HMAC_SHA256(secret, t + "." + raw_body))`.
 * Header `Qhapaq-Signature: t=…,v1=…[,v1=…]` (dos v1 durante la gracia de rotación del secreto).
 */
final class WebhookSigner
{
    public const TOLERANCE = 300;

    /** @param  list<string>  $secrets */
    public static function header(array $secrets, int $timestamp, string $body): string
    {
        $parts = ["t={$timestamp}"];
        foreach ($secrets as $secret) {
            $parts[] = 'v1='.hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
        }

        return implode(',', $parts);
    }

    /** Verificación del receptor: comparación en tiempo constante y rechazo de firmas con más de 5 minutos. */
    public static function verify(string $secret, string $header, string $body, ?int $now = null): bool
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $timestamp = (int) $v;
            } elseif ($k === 'v1') {
                $signatures[] = $v;
            }
        }
        if ($timestamp === null || abs(($now ?? time()) - $timestamp) > self::TOLERANCE) {
            return false;
        }
        $expected = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
