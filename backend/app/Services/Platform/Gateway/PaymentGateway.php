<?php

namespace App\Services\Platform\Gateway;

/**
 * Pasarela de cobro recurrente de la plataforma (spec 13 §8.1, opción B). La pasarela real (Niubiz, Izipay, Culqi o Stripe/Cashier)
 * queda por definir (INT-02, §12-1): se implementa como adapter detrás de esta interfaz.
 */
interface PaymentGateway
{
    /** Cobro con la tarjeta tokenizada. La pasarela deduplica por `$idempotencyKey`. */
    public function charge(string $token, string $amount, string $currency, string $idempotencyKey, string $description): ChargeResult;

    public function refund(string $paymentRef, string $amount, string $idempotencyKey): ChargeResult;

    /** Inicio de la tokenización en el formulario embebido de la pasarela (el PAN nunca llega a nuestro backend). */
    public function createSetupIntent(string $tenantId): array;

    /** Evento entrante firmado de la pasarela; null si la firma no es válida. */
    public function parseWebhook(string $payload, ?string $signature): ?array;
}
