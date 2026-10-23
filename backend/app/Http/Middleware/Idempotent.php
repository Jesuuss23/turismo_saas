<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-BR-16 (RT 22): Idempotency-Key obligatorio en operaciones críticas.
 * - misma key + mismo cuerpo → misma respuesta (header Idempotent-Replayed: true)
 * - misma key + cuerpo distinto → 422 IDEMPOTENCY_KEY_REUSED
 * - key en proceso → 409 IDEMPOTENCY_IN_PROGRESS
 * - key fallida (5xx) → se permite reintentar
 * Tabla sys_idempotency_keys (fuente de verdad) + copia en Redis idem:{tenant}:{key} 24 h.
 */
class Idempotent
{
    private const LOCK_SECONDS = 60;

    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header('Idempotency-Key');
        if ($key === '' || strlen($key) > 80) {
            throw new ApiException(400, 'IDEMPOTENCY_KEY_REQUIRED', 'SEC-BR-16', 'Esta operación exige el header Idempotency-Key');
        }

        $hash = hash('sha256', $this->canonicalBody($request));
        $cacheKey = 'idem:'.($this->tenant->id() ?? 'platform').':'.$key;

        if ($cached = Cache::get($cacheKey)) {
            return $this->replay($cached, $hash);
        }

        $row = $this->claim($request, $key, $hash);
        if ($row !== null) {
            return $this->replay($row, $hash);
        }

        $response = $next($request);

        $status = $response->getStatusCode();
        $completed = $status < 500;
        $body = json_decode((string) $response->getContent(), true);
        $record = [
            'status' => $completed ? 'completed' : 'failed',
            'response_code' => $status,
            'response_body' => json_encode($body),
            'locked_until' => null,
        ];
        DB::table('sys_idempotency_keys')->where('key', $key)->where('tenant_id', $this->tenant->id())->update($record);

        if ($completed) {
            Cache::put($cacheKey, ['request_hash' => $hash, 'status' => 'completed', 'response_code' => $status, 'response_body' => $body], now()->addHours(config('turismo.idempotency_ttl_hours')));
        }

        return $response;
    }

    /** Reserva la key. Devuelve la fila existente si ya estaba tomada, o null si la tomamos. */
    private function claim(Request $request, string $key, string $hash): ?array
    {
        $now = now();
        $values = [
            'status' => 'processing',
            'request_hash' => $hash,
            'locked_until' => $now->copy()->addSeconds(self::LOCK_SECONDS),
            'response_code' => null,
            'response_body' => null,
        ];

        try {
            DB::table('sys_idempotency_keys')->insert($values + [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $this->tenant->id(),
                'key' => $key,
                'user_id' => $request->user()?->getKey(),
                'method' => $request->method(),
                'path' => '/'.$request->path(),
                'created_at' => $now,
                'expires_at' => $now->copy()->addHours(config('turismo.idempotency_ttl_hours')),
            ]);

            return null;
        } catch (UniqueConstraintViolationException) {
            $existing = (array) DB::table('sys_idempotency_keys')->where('key', $key)->where('tenant_id', $this->tenant->id())->first();

            // Un intento fallido (o un lock vencido con el mismo cuerpo) se puede reintentar.
            $stale = $existing['status'] === 'processing' && $existing['locked_until'] !== null && now()->greaterThan($existing['locked_until']);
            if (($existing['status'] === 'failed' || $stale) && $existing['request_hash'] === $hash) {
                $taken = DB::table('sys_idempotency_keys')
                    ->where('id', $existing['id'])->where('status', $existing['status'])
                    ->update($values);
                if ($taken === 1) {
                    return null;
                }
            }

            return $existing;
        }
    }

    private function replay(array $row, string $hash): Response
    {
        if ($row['request_hash'] !== $hash) {
            throw new ApiException(422, 'IDEMPOTENCY_KEY_REUSED', 'SEC-BR-16', 'La Idempotency-Key ya se usó con otro cuerpo de solicitud');
        }
        if ($row['status'] !== 'completed') {
            throw ApiException::conflict('IDEMPOTENCY_IN_PROGRESS', 'La operación con esta Idempotency-Key está en proceso');
        }

        $body = is_string($row['response_body']) ? json_decode($row['response_body'], true) : $row['response_body'];

        return new JsonResponse($body, (int) $row['response_code'], ['Idempotent-Replayed' => 'true']);
    }

    private function canonicalBody(Request $request): string
    {
        $data = $request->all();
        $this->ksortRecursive($data);

        return $request->method().' /'.$request->path().' '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function ksortRecursive(array &$data): void
    {
        ksort($data);
        foreach ($data as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
    }
}
