<?php

namespace App\Providers;

use App\Services\Integrations\WebhookEventBridge;
use App\Services\Platform\Gateway\PaymentGateway;
use App\Services\Platform\Gateway\SandboxPaymentGateway;
use Illuminate\Support\ServiceProvider;

/**
 * Plataforma SaaS (spec 13): pasarela de cobro de la plataforma (sandbox hasta definir INT-02) y eventos de dominio
 * hacia los webhooks del tenant y la medición de uso.
 */
class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, SandboxPaymentGateway::class);
    }

    public function boot(): void
    {
        WebhookEventBridge::register();
    }
}
