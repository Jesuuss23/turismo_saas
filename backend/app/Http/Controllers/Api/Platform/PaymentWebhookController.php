<?php

namespace App\Http\Controllers\Api\Platform;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Platform\Gateway\PaymentGateway;
use App\Services\Platform\PlatformCatalog;
use App\Services\Platform\PlatformInvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Webhook entrante de la pasarela de la plataforma (spec 13 §6): cobro aprobado o rechazado de una factura. Firma de la pasarela
 * e idempotente por id de evento. Ruta propia de la plataforma (la de e-commerce de spec 08 sigue en /webhooks/payments).
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentGateway $gateway, PlatformInvoiceService $invoices, string $gateway_code): JsonResponse
    {
        $event = $gateway->parseWebhook($request->getContent(), $request->header('X-Signature'));
        if ($event === null) {
            throw new ApiException(401, 'INVALID_SIGNATURE', 'SUB-BR-12', 'Firma de la pasarela inválida');
        }
        $id = (string) ($event['id'] ?? '');
        if ($id === '' || ! Cache::add("platform-payment-event:{$gateway_code}:{$id}", 1, 86400 * 7)) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }
        $invoice = isset($event['invoice']) ? $invoices->byNumber((string) $event['invoice']) : null;
        if ($invoice && ($event['type'] ?? '') === 'charge.succeeded' && $invoice->status !== 'paid') {
            DB::connection(PlatformCatalog::CONNECTION)->transaction(fn () => $invoices->markPaid($invoice, $event['reference'] ?? $id, null, 'cobro_exitoso'));
        }

        return response()->json(['received' => true]);
    }
}
