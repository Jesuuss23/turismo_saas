<?php

namespace App\Http\Controllers\Api\Collab;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Collab\InboundProcessor;
use App\Services\Collab\InboxService;
use App\Services\Marketing\MarketingWebhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Entradas de canal (spec 10 §6, INB-BR-08): WhatsApp Cloud API (firma HMAC `X-Hub-Signature-256` y verificación de suscripción),
 * email inbound (token del canal) y formulario/chat web público (rate limit). El tenant llega por host o `X-Tenant-Slug`.
 */
class InboundWebhookController extends Controller
{
    /** Valores del sandbox cuando la cuenta del canal aún no tiene credenciales propias. */
    public const SANDBOX_WHATSAPP_SECRET = 'whatsapp-sandbox-secret';

    public const SANDBOX_VERIFY_TOKEN = 'whatsapp-sandbox-verify';

    public const SANDBOX_EMAIL_TOKEN = 'email-sandbox-token';

    /** Spec 14: token de los eventos del ESP (entregado, abierto, clic, rebote, baja) en sandbox. */
    public const SANDBOX_ESP_TOKEN = 'esp-sandbox-token';

    public function __construct(private readonly InboundProcessor $processor, private readonly InboxService $inbox, private readonly MarketingWebhooks $marketing) {}

    public function whatsappVerify(Request $request): Response
    {
        $expected = $this->inbox->account('whatsapp')?->credentials_encrypted['verify_token'] ?? self::SANDBOX_VERIFY_TOKEN;
        if ($request->query('hub_mode') !== 'subscribe' || ! hash_equals($expected, (string) $request->query('hub_verify_token'))) {
            return response('Forbidden', 403);
        }

        return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
    }

    public function whatsapp(Request $request): JsonResponse
    {
        $secret = $this->inbox->account('whatsapp')?->webhook_secret_encrypted ?? self::SANDBOX_WHATSAPP_SECRET;
        $signature = (string) $request->header('X-Hub-Signature-256');
        if (! hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            throw new ApiException(401, 'INVALID_SIGNATURE', 'INB-BR-08', 'Firma del webhook inválida');
        }
        $results = [];
        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? [];
                $names = collect($value['contacts'] ?? [])->mapWithKeys(fn ($c) => [($c['wa_id'] ?? '') => $c['profile']['name'] ?? null]);
                foreach ((array) ($value['messages'] ?? []) as $m) {
                    $from = (string) ($m['from'] ?? '');
                    // Spec 14: respuesta a una campaña (y «BAJA» revoca el consentimiento de WhatsApp, MKT-BR-02).
                    $this->marketing->whatsappReply($from, (string) ($m['text']['body'] ?? $m['button']['text'] ?? ''));
                    $results[] = $this->processor->process('whatsapp', [
                        'external_message_id' => (string) ($m['id'] ?? Str::uuid()), 'thread_id' => $from, 'name' => $names[$from] ?? "+{$from}",
                        'phone' => "+{$from}", 'body' => (string) ($m['text']['body'] ?? $m['button']['text'] ?? '[mensaje no textual]'),
                        'at' => isset($m['timestamp']) ? date('c', (int) $m['timestamp']) : null,
                    ], (string) ($m['id'] ?? null), $m)['status'];
                }
                foreach ((array) ($value['statuses'] ?? []) as $s) {
                    $this->processor->deliveryStatus('whatsapp', (string) ($s['id'] ?? ''), (string) ($s['status'] ?? ''), $s['errors'][0]['title'] ?? null);
                    $this->marketing->whatsappStatus((string) ($s['id'] ?? ''), (string) ($s['status'] ?? ''));
                    $results[] = 'estado';
                }
            }
        }

        return response()->json(['data' => ['received' => count($results), 'results' => $results]]);
    }

    /** Spec 14 §6: estado de plantillas de Meta (`message_template_status_update`), con la misma firma HMAC. */
    public function whatsappTemplates(Request $request): JsonResponse
    {
        $this->assertWhatsAppSignature($request);
        $results = [];
        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? [];
                $t = $this->marketing->templateStatus((string) ($value['message_template_name'] ?? ''), (string) ($value['event'] ?? ''));
                $results[] = $t ? "{$t->code}:{$t->status}" : 'ignorado';
            }
        }

        return response()->json(['data' => ['received' => count($results), 'results' => $results]]);
    }

    /** Spec 14 §6: eventos del ESP de marketing (delivered/opened/clicked/bounced/unsubscribed) por `X-Esp-Token`. */
    public function emailEvents(Request $request): JsonResponse
    {
        if (! hash_equals(self::SANDBOX_ESP_TOKEN, (string) $request->header('X-Esp-Token'))) {
            throw new ApiException(401, 'INVALID_TOKEN', 'INB-BR-08', 'Token del proveedor de email inválido');
        }
        $events = $request->has('events') ? (array) $request->input('events') : [$request->all()];
        $results = [];
        foreach ($events as $e) {
            $results[] = $this->marketing->emailEvent((string) ($e['message_id'] ?? ''), (string) ($e['event'] ?? '')) ?? 'ignorado';
        }

        return response()->json(['data' => ['received' => count($results), 'results' => $results]]);
    }

    private function assertWhatsAppSignature(Request $request): void
    {
        $secret = $this->inbox->account('whatsapp')?->webhook_secret_encrypted ?? self::SANDBOX_WHATSAPP_SECRET;
        if (! hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), (string) $request->header('X-Hub-Signature-256'))) {
            throw new ApiException(401, 'INVALID_SIGNATURE', 'INB-BR-08', 'Firma del webhook inválida');
        }
    }

    public function email(Request $request): JsonResponse
    {
        $token = $this->inbox->account('email')?->credentials_encrypted['inbound_token'] ?? self::SANDBOX_EMAIL_TOKEN;
        if (! hash_equals($token, (string) $request->header('X-Inbound-Token'))) {
            throw new ApiException(401, 'INVALID_TOKEN', 'INB-BR-08', 'Token del canal de email inválido');
        }
        $data = $request->validate(['message_id' => 'required|string|max:190', 'in_reply_to' => 'nullable|string|max:190', 'references' => 'nullable|string|max:2000',
            'from_email' => 'required|email|max:190', 'from_name' => 'nullable|string|max:160', 'subject' => 'nullable|string|max:200', 'text' => 'required|string|max:20000']);
        // Hilo = Message-ID raíz (primer `References`) o el propio mensaje si inicia el hilo.
        $root = trim(explode(' ', trim((string) ($data['references'] ?? '')))[0] ?? '') ?: ($data['in_reply_to'] ?? null) ?: $data['message_id'];
        $result = $this->processor->process('email', ['external_message_id' => $data['message_id'], 'thread_id' => $root, 'name' => $data['from_name'] ?? $data['from_email'],
            'email' => $data['from_email'], 'subject' => $data['subject'] ?? null, 'body' => $data['text']], $data['message_id'], $data);

        return response()->json(['data' => $result], 202);
    }

    /** Formulario de contacto y chat web del sitio público. */
    public function contact(Request $request): JsonResponse
    {
        $data = $request->validate(['channel' => 'nullable|in:form,webchat', 'session_id' => 'nullable|string|max:120', 'name' => 'required|string|max:160',
            'email' => 'nullable|email|max:190', 'phone' => 'nullable|string|max:30', 'subject' => 'nullable|string|max:200', 'message' => 'required|string|max:5000'],
            ['name.required' => 'Indique su nombre.', 'message.required' => 'Escriba su mensaje.']);
        if (empty($data['email']) && empty($data['phone'])) {
            throw ApiException::invalid('email', 'Indique un correo o un teléfono de contacto.');
        }
        $channel = $data['channel'] ?? 'form';
        $id = 'web-'.Str::uuid();
        $result = $this->processor->process($channel, ['external_message_id' => $id, 'thread_id' => $channel === 'webchat' ? ($data['session_id'] ?? $id) : null,
            'name' => $data['name'], 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null, 'subject' => $data['subject'] ?? null, 'body' => $data['message']], $id, $data);

        return response()->json(['data' => ['status' => $result['status'], 'message' => 'Recibimos su mensaje; un asesor le responderá pronto.']], 202);
    }
}
