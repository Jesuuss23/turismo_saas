<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Error de API con el formato común (§6):
 * { error, code, message, required_permissions?, correlation_id } + datos extra de la regla
 * (p. ej. `duplicates` en CRM-BR-04).
 */
class ApiException extends RuntimeException
{
    /**
     * @param  list<string>  $requiredPermissions
     */
    public function __construct(
        public readonly int $status,
        public readonly string $error,
        public readonly string $errorCode,
        string $message,
        public readonly array $requiredPermissions = [],
        public readonly array $extra = [],
        /** Headers de la respuesta (p. ej. Retry-After en 429). */
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function forbidden(string $code, string $message, array $required = []): self
    {
        return new self(403, 'FORBIDDEN', $code, $message, $required);
    }

    public static function notFound(): self
    {
        return new self(404, 'RESOURCE_NOT_FOUND', 'RESOURCE_NOT_FOUND', 'El recurso solicitado no existe');
    }

    public static function unauthenticated(string $message = 'Sesión no válida o expirada'): self
    {
        return new self(401, 'UNAUTHENTICATED', 'UNAUTHENTICATED', $message);
    }

    public static function mfaRequired(): self
    {
        return new self(403, 'MFA_REQUIRED', 'SEC-BR-08', 'Complete la verificación MFA para continuar');
    }

    public static function tenantSuspended(): self
    {
        return new self(403, 'TENANT_SUSPENDED', 'SEC-BR-24', 'La cuenta de la agencia está suspendida');
    }

    public static function tenantScopeViolation(): self
    {
        return new self(403, 'TENANT_SCOPE_VIOLATION', 'SEC-BR-02', 'No puede operar sobre otro tenant');
    }

    public static function conflict(string $error, string $message, array $extra = []): self
    {
        return new self(409, $error, $error, $message, [], $extra);
    }

    /** Regla de negocio incumplida (409 con el código de la regla, p. ej. CRM-BR-10). */
    public static function rule(string $rule, string $message, array $extra = []): self
    {
        return new self(409, 'BUSINESS_RULE', $rule, $message, [], $extra);
    }

    /** Regla de negocio incumplida al validar la operación (422 con el código de la regla, p. ej. PRD-BR-002). */
    public static function unprocessable(string $rule, string $message, array $extra = []): self
    {
        return new self(422, 'BUSINESS_RULE', $rule, $message, [], $extra);
    }

    /** Error de validación de negocio en un campo (422). */
    public static function invalid(string $field, string $message): self
    {
        return new self(422, 'VALIDATION_ERROR', 'VALIDATION_ERROR', $message, [], ['errors' => [$field => [$message]]]);
    }
}
