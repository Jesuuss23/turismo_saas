<?php

namespace App\Services\Platform\Gateway;

use App\Services\Platform\PlatformCatalog;
use Illuminate\Support\Str;

/**
 * Pasarela sandbox determinista: el token `tok_sandbox_{marca}_{last4}_{aleatorio}` sale del formulario embebido simulado.
 * Tarjetas que terminan en 0002 / 9995 / 0069 se rechazan (tarjeta rechazada, fondos insuficientes, vencida).
 */
class SandboxPaymentGateway implements PaymentGateway
{
    public function charge(string $token, string $amount, string $currency, string $idempotencyKey, string $description): ChargeResult
    {
        if (! preg_match('/^tok_sandbox_[a-z]+_(\d{4})_[A-Za-z0-9]+$/', $token, $m)) {
            return new ChargeResult(false, null, 'invalid_token', 'Token de tarjeta inválido o vencido', true);
        }
        if ($decline = PlatformCatalog::SANDBOX_DECLINES[$m[1]] ?? null) {
            return new ChargeResult(false, null, $decline[0], $decline[1]);
        }

        return new ChargeResult(true, 'ch_'.substr(hash('sha256', $idempotencyKey), 0, 20));
    }

    public function refund(string $paymentRef, string $amount, string $idempotencyKey): ChargeResult
    {
        return new ChargeResult(true, 're_'.substr(hash('sha256', $idempotencyKey), 0, 20));
    }

    public function createSetupIntent(string $tenantId): array
    {
        return ['client_secret' => 'seti_'.Str::random(24), 'mode' => 'sandbox', 'test_cards' => [
            ['number' => '4111 1111 1111 1111', 'result' => 'Aprobada'],
            ['number' => '4000 0000 0000 0002', 'result' => 'Rechazada'],
            ['number' => '4000 0000 0000 9995', 'result' => 'Fondos insuficientes'],
        ]];
    }

    public function parseWebhook(string $payload, ?string $signature): ?array
    {
        $expected = hash_hmac('sha256', $payload, (string) config('turismo.payments.webhook_secret'));

        return $signature !== null && hash_equals($expected, $signature) ? json_decode($payload, true) : null;
    }
}
