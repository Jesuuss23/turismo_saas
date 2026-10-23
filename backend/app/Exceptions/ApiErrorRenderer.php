<?php

namespace App\Exceptions;

use App\Models\Iam\User;
use App\Security\AccessMap;
use App\Services\AuditLogger;
use App\Services\CorrelationId;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Traduce excepciones al formato de error común (§6) y audita los accesos denegados
 * (SEC-BR-07) y los intentos cross-tenant (SEC-BR-02, E2E-08).
 */
class ApiErrorRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        $api = $this->toApiException($e, $request);
        if ($api === null) {
            return null;
        }

        if ($api->status === 403 && $api->error !== 'MFA_REQUIRED') {
            $this->auditDenied($request, $api);
        }

        $body = [
            'error' => $api->error,
            'code' => $api->errorCode,
            'message' => $api->getMessage(),
        ];
        if ($api->requiredPermissions !== []) {
            $body['required_permissions'] = $api->requiredPermissions;
        }
        if ($e instanceof ValidationException) {
            $body['errors'] = $e->errors();
        }
        $body += $api->extra;
        $body['correlation_id'] = app(CorrelationId::class)->get();

        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

        return new JsonResponse($body, $api->status, $headers);
    }

    private function toApiException(Throwable $e, Request $request): ?ApiException
    {
        return match (true) {
            $e instanceof ApiException => $e,
            $e instanceof AuthenticationException => ApiException::unauthenticated(),
            $e instanceof ValidationException => new ApiException(422, 'VALIDATION_ERROR', 'VALIDATION_ERROR', $e->getMessage()),
            $e instanceof ModelNotFoundException => $this->notFound($e, $request),
            $e instanceof NotFoundHttpException && $e->getPrevious() instanceof ModelNotFoundException => $this->notFound($e->getPrevious(), $request),
            $e instanceof NotFoundHttpException => ApiException::notFound(),
            $e instanceof UnauthorizedException => ApiException::forbidden('SEC-BR-03', 'No tiene permiso para esta acción', $e->getRequiredPermissions()),
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => ApiException::forbidden(
                'SEC-BR-03',
                $e->getMessage() !== '' && $e->getMessage() !== 'This action is unauthorized.' ? $e->getMessage() : 'No tiene permiso para esta acción',
            ),
            $e instanceof TooManyRequestsHttpException => new ApiException(429, 'TOO_MANY_REQUESTS', 'SEC-BR-25', 'Demasiadas solicitudes; intente más tarde'),
            default => null,
        };
    }

    /**
     * 404 sin revelar existencia (§11-3). Si el id existe en otro tenant, se audita como intento
     * cross-tenant consultando con la conexión de administración (fuera de RLS).
     */
    private function notFound(ModelNotFoundException $e, Request $request): ApiException
    {
        $model = $e->getModel();
        $ids = $e->getIds();
        if ($model && $ids && app(TenantContext::class)->has()) {
            $instance = new $model;
            try {
                $owner = DB::connection('pgsql_admin')->table($instance->getTable())
                    ->whereIn($instance->getKeyName(), (array) $ids)
                    ->value('tenant_id');
            } catch (Throwable) {
                $owner = null;
            }

            if ($owner !== null && $owner !== app(TenantContext::class)->id()) {
                app(AuditLogger::class)->isolated(
                    class_basename($model), (string) implode(',', (array) $ids), 'acceso_denegado',
                    $this->roleOf($request), 'recurso de otro tenant (404)',
                );
            }
        }

        return ApiException::notFound();
    }

    private function auditDenied(Request $request, ApiException $api): void
    {
        $after = $api->requiredPermissions !== []
            ? 'requiere '.implode(' | ', $api->requiredPermissions)
            : ($api->error === 'TENANT_SCOPE_VIOLATION' ? 'intento de fijar otro tenant' : 'fuera del alcance del rol');

        try {
            app(AuditLogger::class)->isolated('Acceso', '/'.AccessMap::normalize($request->path()), 'acceso_denegado', $this->roleOf($request), $after);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function roleOf(Request $request): ?string
    {
        $user = $request->user();

        return $user instanceof User ? $user->roleName() : ($user?->roleName() ?? null);
    }
}
