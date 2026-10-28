<?php

namespace App\Http\Controllers\Api\Experience;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Experience\PaxMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Acuses de los proveedores de mensajería (spec 17 §6: SES, WhatsApp Cloud API, FCM) normalizados como
 * `{events: [{message_id, status: delivered|read|failed, error?, at?}]}` con firma HMAC-SHA256 del cuerpo. Dedupe por provider_message_id.
 */
class ProviderAckController extends Controller
{
    public const SANDBOX_SECRET = 'sandbox-pax-provider-secret';

    public function __invoke(Request $request, string $provider, PaxMessageService $messages): JsonResponse
    {
        $secret = (string) config("turismo.pax_provider_secrets.{$provider}", self::SANDBOX_SECRET);
        if (! hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), (string) $request->header('X-Provider-Signature'))) {
            throw new ApiException(401, 'INVALID_SIGNATURE', 'MSG-ACK', 'Firma del proveedor inválida');
        }
        $results = [];
        foreach ((array) $request->input('events', []) as $e) {
            $id = (string) ($e['message_id'] ?? '');
            $results[] = ['message_id' => $id, 'status' => $id === '' ? null : $messages->ack($id, (string) ($e['status'] ?? ''), $e['error'] ?? null, $e['at'] ?? null)];
        }

        return response()->json(['data' => $results]);
    }
}
