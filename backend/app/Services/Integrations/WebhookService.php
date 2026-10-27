<?php

namespace App\Services\Integrations;

use App\Exceptions\ApiException;
use App\Jobs\DeliverWebhookJob;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Platform\LimitService;
use App\Services\Platform\PlatformCatalog;
use App\Services\Platform\PlatformEvents;
use App\Services\SequenceService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Webhooks salientes del tenant (spec 13 §3.12–3.13, §4.5, §8.4, API-BR-04..08): alta con anti-SSRF, firma HMAC, entrega con
 * backoff 1 min → 24 h (6 intentos), deshabilitado tras 10 fallos consecutivos o 410, rotación con 24 h de gracia y reenvío manual.
 * Opera dentro del contexto del tenant (RLS).
 */
class WebhookService
{
    public const STATUS = ['active' => 'Activo', 'paused' => 'Pausado', 'disabled' => 'Deshabilitado', 'deleted' => 'Eliminado'];

    public const DELIVERY_STATUS = ['pending' => 'Pendiente', 'delivered' => 'Entregado', 'retrying' => 'Reintentando', 'failed' => 'Fallido'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly SafeUrlValidator $urls,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly LimitService $limits,
        private readonly PlatformEvents $events,
    ) {}

    public function list(): array
    {
        $since = now()->subDay();
        $stats = DB::table('integration_webhook_deliveries')->where('created_at', '>=', $since)->groupBy('webhook_id')
            ->selectRaw("webhook_id, count(*) filter (where status = 'delivered') as ok, count(*) filter (where status in ('failed','retrying')) as ko")->get()->keyBy('webhook_id');

        return DB::table('integration_webhooks')->whereNull('deleted_at')->orderBy('code')->get()->map(fn ($w) => $this->row($w, $stats[$w->id] ?? null))->all();
    }

    public function row(object $w, ?object $stats = null): array
    {
        $events = json_decode($w->events, true);

        return ['id' => $w->id, 'code' => $w->code, 'url' => $w->url, 'description' => $w->description, 'events' => $events,
            'event_labels' => array_map(fn ($e) => PlatformCatalog::WEBHOOK_EVENTS[$e] ?? $e, $events), 'secret_prefix' => $w->secret_prefix,
            'api_version' => $w->api_version, 'status' => $w->status, 'status_label' => self::STATUS[$w->status], 'consecutive_failures' => (int) $w->consecutive_failures,
            'disabled_reason' => $w->disabled_reason, 'rotation_grace_until' => $w->previous_secret_expires_at && Carbon::parse($w->previous_secret_expires_at)->isFuture()
                ? $w->previous_secret_expires_at : null, 'created_at' => $w->created_at,
            'delivered_24h' => (int) ($stats->ok ?? 0), 'failed_24h' => (int) ($stats->ko ?? 0)];
    }

    /** API-BR-04/05/06: máximo según el plan, HTTPS y anti-SSRF. El secreto solo va en la respuesta 201. */
    public function create(array $data, ?User $actor, ?string $createdBy = null): array
    {
        $tenant = $this->context->id();
        $this->limits->assertWithin($tenant, 'webhooks', 1, 'API-BR-04');
        $url = trim((string) ($data['url'] ?? ''));
        $this->urls->assert($url);
        $events = $this->events((array) ($data['events'] ?? []));
        $secret = 'whsec_'.Str::random(32);
        $id = (string) Str::uuid7();
        $code = $this->sequences->next('webhook');
        DB::table('integration_webhooks')->insert(['id' => $id, 'tenant_id' => $tenant, 'code' => $code, 'url' => $url,
            'description' => isset($data['description']) ? Str::limit((string) $data['description'], 195) : null, 'events' => json_encode($events),
            'secret_encrypted' => Crypt::encryptString($secret), 'secret_prefix' => substr($secret, 0, 10), 'api_version' => PlatformCatalog::API_VERSION,
            'status' => 'active', 'consecutive_failures' => 0, 'created_by' => $actor?->id ?? $createdBy, 'created_at' => now(), 'updated_at' => now()]);
        Cache::forget(WebhookEventBridge::flagKey($tenant));
        $this->audit->log('Webhook', $code, 'crear', null, $url.' · '.count($events).' evento(s)', actor: $actor, system: $actor === null);

        return $this->row($this->find($id)) + ['secret' => $secret];
    }

    /** Editar URL/eventos/descripción y pausar, reanudar o reactivar. Cambiar la URL resetea el contador de fallos (§4.5). */
    public function update(string $id, array $data, User $actor): array
    {
        $w = $this->find($id);
        if ($w->status === 'deleted') {
            throw ApiException::notFound();
        }
        $update = [];
        $actions = [];
        if (array_key_exists('url', $data) && trim((string) $data['url']) !== $w->url) {
            $url = trim((string) $data['url']);
            $this->urls->assert($url);
            $update += ['url' => $url, 'consecutive_failures' => 0];
            $actions[] = ['editar', $w->url, $url];
        }
        if (array_key_exists('events', $data)) {
            $events = $this->events((array) $data['events']);
            $update['events'] = json_encode($events);
            $actions[] = ['editar', count(json_decode($w->events, true)).' evento(s)', count($events).' evento(s)'];
        }
        if (array_key_exists('description', $data)) {
            $update['description'] = $data['description'] === null ? null : Str::limit((string) $data['description'], 195);
        }
        if (isset($data['status'])) {
            [$status, $action] = match ([$w->status, $data['status']]) {
                ['active', 'paused'] => ['paused', 'pausar'],
                ['paused', 'active'], ['disabled', 'active'] => ['active', 'reactivar'],
                default => throw ApiException::conflict('WEBHOOK_INVALID_TRANSITION', 'Transición no permitida desde «'.self::STATUS[$w->status].'».'),
            };
            $update += ['status' => $status, 'disabled_reason' => null] + ($w->status === 'disabled' ? ['consecutive_failures' => 0] : []);
            $actions[] = [$action, self::STATUS[$w->status], self::STATUS[$status]];
        }
        if ($update === []) {
            throw ApiException::invalid('url', 'No hay cambios.');
        }
        DB::table('integration_webhooks')->where('id', $id)->update($update + ['updated_at' => now()]);
        Cache::forget(WebhookEventBridge::flagKey($w->tenant_id));
        foreach ($actions as [$action, $before, $after]) {
            $this->audit->log('Webhook', $w->code, $action, $before, $after, actor: $actor);
        }

        return $this->row($this->find($id));
    }

    public function delete(string $id, User $actor): void
    {
        $w = $this->find($id);
        DB::table('integration_webhooks')->where('id', $id)->update(['status' => 'deleted', 'deleted_at' => now(), 'updated_at' => now()]);
        Cache::forget(WebhookEventBridge::flagKey($w->tenant_id));
        $this->audit->log('Webhook', $w->code, 'eliminar', self::STATUS[$w->status], 'Eliminado', actor: $actor);
    }

    /** API-BR-08: nuevo secreto; el anterior se acepta 24 h (se envían ambas firmas v1). */
    public function rotate(string $id, User $actor): array
    {
        $w = $this->find($id);
        $secret = 'whsec_'.Str::random(32);
        DB::table('integration_webhooks')->where('id', $id)->update(['previous_secret_encrypted' => $w->secret_encrypted, 'previous_secret_expires_at' => now()->addDay(),
            'secret_encrypted' => Crypt::encryptString($secret), 'secret_prefix' => substr($secret, 0, 10), 'updated_at' => now()]);
        $this->audit->log('Webhook', $w->code, 'rotar_secreto', "{$w->secret_prefix}…", substr($secret, 0, 10).'… · gracia 24 h', actor: $actor);

        return $this->row($this->find($id)) + ['secret' => $secret];
    }

    /** Evento `ping` síncrono. */
    public function ping(string $id, User $actor): array
    {
        $w = $this->find($id);
        $delivery = $this->createDelivery($w, 'ping', $this->eventId(), null, ['message' => 'Ping de Qhapaq Cloud', 'webhook' => $w->code], true);
        $result = $this->send($delivery, countFailure: false);
        $this->audit->log('Webhook', $w->code, 'ping', null, "HTTP {$result->http_status} · {$result->duration_ms} ms", actor: $actor);

        return $this->deliveryRow($result);
    }

    // ───────────────────────────── Entregas ─────────────────────────────

    public function deliveries(array $filters = []): array
    {
        $codes = DB::table('integration_webhooks')->pluck('code', 'id');

        return DB::table('integration_webhook_deliveries')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['webhook'] ?? null, fn ($q, $w) => $q->where('webhook_id', $w))
            ->when($filters['event'] ?? null, fn ($q, $e) => $q->where('event_type', $e))
            ->orderByDesc('created_at')->limit(200)->get()->map(fn ($d) => $this->deliveryRow($d, $codes[$d->webhook_id] ?? null))->all();
    }

    public function deliveryRow(object $d, ?string $webhookCode = null): array
    {
        return ['id' => $d->id, 'code' => $d->code, 'webhook_id' => $d->webhook_id,
            'webhook_code' => $webhookCode ?? DB::table('integration_webhooks')->where('id', $d->webhook_id)->value('code'), 'event_type' => $d->event_type,
            'event_id' => $d->event_id, 'entity_code' => $d->entity_code, 'attempt' => (int) $d->attempt, 'status' => $d->status,
            'status_label' => self::DELIVERY_STATUS[$d->status], 'http_status' => $d->http_status === null ? null : (int) $d->http_status,
            'duration_ms' => $d->duration_ms === null ? null : (int) $d->duration_ms, 'error' => $d->error, 'next_retry_at' => $d->next_retry_at,
            'manual' => (bool) $d->manual, 'created_at' => $d->created_at, 'updated_at' => $d->updated_at];
    }

    public function delivery(string $id): array
    {
        $d = (Str::isUuid($id) ? DB::table('integration_webhook_deliveries')->where('id', $id)->first() : null) ?? throw ApiException::notFound();

        return $this->deliveryRow($d) + ['payload' => json_decode($d->payload, true), 'request_headers' => json_decode((string) $d->request_headers, true) ?: [],
            'response_excerpt' => $d->response_excerpt];
    }

    /** Reenvío manual: crea una nueva entrega con `manual=true` y la envía en el acto. */
    public function redeliver(string $id, ?User $actor): array
    {
        $d = (Str::isUuid($id) ? DB::table('integration_webhook_deliveries')->where('id', $id)->first() : null) ?? throw ApiException::notFound();
        $w = $this->find($d->webhook_id);
        if ($w->status === 'deleted') {
            throw ApiException::conflict('WEBHOOK_DELETED', 'El webhook fue eliminado.');
        }
        $payload = json_decode($d->payload, true);
        $copy = $this->createDelivery($w, $d->event_type, $d->event_id, $d->entity_code, $payload['data'] ?? [], true, $payload);
        $result = $this->send($copy);
        $this->audit->log('Webhook', $w->code, 'reenviar', $d->code, "{$copy->code} · HTTP ".($result->http_status ?? '—'), actor: $actor, system: $actor === null);

        return $this->deliveryRow($result) + ['webhook' => $this->row($this->find($w->id))];
    }

    // ───────────────────────────── Despacho y envío ─────────────────────────────

    /** §8.4: por cada webhook activo suscrito al evento crea una entrega `pending` y encola el envío. */
    public function dispatch(string $type, ?string $entityCode, array $data): int
    {
        $hooks = DB::table('integration_webhooks')->where('status', 'active')->whereNull('deleted_at')->whereRaw('events @> ?::jsonb', [json_encode([$type])])->get();
        $eventId = $this->eventId();
        foreach ($hooks as $w) {
            $delivery = $this->createDelivery($w, $type, $eventId, $entityCode, $data, false);
            DeliverWebhookJob::dispatch($w->tenant_id, $delivery->id)->afterCommit();
        }

        return $hooks->count();
    }

    /**
     * Un intento de entrega: revalida SSRF, firma y envía (timeout 10 s, sin redirecciones). 2xx = entregado; 410 = deshabilitar;
     * otro código o timeout = reintento con backoff (las manuales no se reintentan). 10 fallos consecutivos deshabilitan el webhook.
     */
    public function send(object $delivery, bool $countFailure = true): object
    {
        $w = $this->find($delivery->webhook_id);
        $body = json_encode(json_decode($delivery->payload, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $secrets = [Crypt::decryptString($w->secret_encrypted)];
        if ($w->previous_secret_encrypted && $w->previous_secret_expires_at && Carbon::parse($w->previous_secret_expires_at)->isFuture()) {
            $secrets[] = Crypt::decryptString($w->previous_secret_encrypted);
        }
        $headers = ['Content-Type' => 'application/json', 'User-Agent' => 'Qhapaq-Webhooks/1.0', 'Qhapaq-Event-Id' => $delivery->event_id,
            'Qhapaq-Event-Type' => $delivery->event_type, 'Qhapaq-Delivery-Id' => $delivery->code, 'Qhapaq-Signature' => WebhookSigner::header($secrets, time(), $body)];
        $attempt = (int) $delivery->attempt + 1;
        $started = microtime(true);
        [$status, $excerpt, $error] = $this->post($w->url, $headers, $body);
        $ms = (int) round((microtime(true) - $started) * 1000);
        if ($status !== null && SafeUrlValidator::isSandbox((string) parse_url($w->url, PHP_URL_HOST))) {
            $ms = 80 + crc32($delivery->code.$attempt) % 400;
        }
        $ok = $status !== null && $status >= 200 && $status < 300;
        $update = ['attempt' => $attempt, 'http_status' => $status, 'duration_ms' => $ms, 'response_excerpt' => $excerpt === null ? null : Str::limit($excerpt, 1990),
            'error' => $error ? Str::limit($error, 295) : null, 'request_headers' => json_encode($headers), 'updated_at' => now()];
        if ($ok) {
            $update += ['status' => 'delivered', 'next_retry_at' => null];
        } elseif ($delivery->manual || $status === 410 || $attempt >= count(PlatformCatalog::WEBHOOK_BACKOFF)) {
            $update += ['status' => 'failed', 'next_retry_at' => null];
        } else {
            $update += ['status' => 'retrying', 'next_retry_at' => now()->addSeconds(PlatformCatalog::WEBHOOK_BACKOFF[$attempt - 1])];
        }
        DB::table('integration_webhook_deliveries')->where('id', $delivery->id)->update($update);

        if ($ok) {
            DB::table('integration_webhooks')->where('id', $w->id)->update(['consecutive_failures' => 0]);
        } elseif ($countFailure) {
            $failures = (int) $w->consecutive_failures + 1;
            DB::table('integration_webhooks')->where('id', $w->id)->update(['consecutive_failures' => $failures, 'updated_at' => now()]);
            if ($w->status === 'active' && ($status === 410 || $failures >= PlatformCatalog::WEBHOOK_MAX_FAILURES)) {
                $this->disable($w, $status === 410 ? 'El receptor respondió 410 Gone.' : "{$failures} fallos consecutivos.");
            }
        }

        return DB::table('integration_webhook_deliveries')->where('id', $delivery->id)->first();
    }

    /** API-BR-07: deshabilitado automático con aviso al Tenant Admin. */
    private function disable(object $w, string $reason): void
    {
        DB::table('integration_webhooks')->where('id', $w->id)->update(['status' => 'disabled', 'disabled_reason' => $reason, 'updated_at' => now()]);
        Cache::forget(WebhookEventBridge::flagKey($w->tenant_id));
        // Las entregas pendientes de reintento se cierran: el webhook ya no recibe eventos.
        DB::table('integration_webhook_deliveries')->where('webhook_id', $w->id)->where('status', 'retrying')->update(['status' => 'failed', 'next_retry_at' => null]);
        $this->audit->log('Webhook', $w->code, 'deshabilitar_automatico', self::STATUS[$w->status], "Deshabilitado · {$reason}", system: true);
        $this->events->notifyTenant($w->tenant_id, 'webhook.deshabilitado', "Webhook {$w->code} deshabilitado",
            "Deshabilitamos el webhook {$w->url}: {$reason} Revise el receptor y reactívelo desde API › Webhooks.");
    }

    /** @return array{0: ?int, 1: ?string, 2: ?string} [HTTP, extracto de respuesta, error] */
    protected function post(string $url, array $headers, string $body): array
    {
        if ($problem = $this->urls->problem($url)) {
            return [null, null, $problem];
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (SafeUrlValidator::isSandbox($host)) {
            $path = (string) parse_url($url, PHP_URL_PATH);

            return match (true) {
                str_contains($path, '/fail') => [500, '{"error":"Internal Server Error"}', 'HTTP 500'],
                str_contains($path, '/gone') => [410, '{"error":"Gone"}', 'HTTP 410'],
                str_contains($path, '/slow') => [null, null, 'Timeout: el receptor no respondió en 10 s'],
                default => [200, '{"received":true}', null],
            };
        }
        try {
            $response = Http::withOptions(['allow_redirects' => false, 'connect_timeout' => 10])->timeout((int) config('turismo.webhooks.timeout', 10))
                ->withHeaders($headers)->withBody($body, 'application/json')->post($url);

            return [$response->status(), $response->body(), $response->successful() ? null : "HTTP {$response->status()}"];
        } catch (ConnectionException $e) {
            return [null, null, 'Timeout o conexión rechazada'];
        } catch (Throwable $e) {
            Log::warning('Webhook: error de envío', ['error' => $e->getMessage()]);

            return [null, null, 'Error de red'];
        }
    }

    private function createDelivery(object $w, string $type, string $eventId, ?string $entityCode, array $data, bool $manual, ?array $payload = null): object
    {
        $id = (string) Str::uuid7();
        $payload ??= ['id' => $eventId, 'type' => $type, 'api_version' => $w->api_version, 'created_at' => now()->toIso8601String(), 'tenant_id' => $w->tenant_id, 'data' => $data];
        DB::table('integration_webhook_deliveries')->insert(['id' => $id, 'tenant_id' => $w->tenant_id, 'code' => $this->sequences->next('webhook_delivery'),
            'webhook_id' => $w->id, 'event_type' => $type, 'event_id' => $eventId, 'entity_code' => $entityCode, 'payload' => json_encode($payload), 'attempt' => 0,
            'status' => 'pending', 'manual' => $manual, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('integration_webhook_deliveries')->where('id', $id)->first();
    }

    private function events(array $events): array
    {
        $events = array_values(array_unique(array_filter($events, 'is_string')));
        if ($events === []) {
            throw ApiException::invalid('events', 'Elija al menos un evento.');
        }
        foreach ($events as $event) {
            if (! isset(PlatformCatalog::WEBHOOK_EVENTS[$event])) {
                throw ApiException::invalid('events', "Evento desconocido: {$event}.");
            }
        }

        return $events;
    }

    private function eventId(): string
    {
        return 'evt_'.Str::lower((string) Str::ulid());
    }

    public function find(string $id): object
    {
        return (Str::isUuid($id) ? DB::table('integration_webhooks')->where('id', $id)->first() : null) ?? throw ApiException::notFound();
    }
}
