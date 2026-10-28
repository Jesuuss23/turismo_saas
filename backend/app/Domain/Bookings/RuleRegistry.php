<?php

namespace App\Domain\Bookings;

/**
 * Handlers de reglas agregadas por el tenant (spec 16 §3.1, §12 #1): catálogo cerrado. Una regla `is_system=false`
 * se lista y se puede activar en el centro, pero el motor solo la evalúa si su clave tiene handler registrado aquí.
 */
final class RuleRegistry
{
    /** @var array<string, class-string> */
    private static array $handlers = [];

    public static function register(string $ruleKey, string $handler): void
    {
        self::$handlers[$ruleKey] = $handler;
    }

    public static function has(string $ruleKey): bool
    {
        return isset(self::$handlers[$ruleKey]);
    }

    /** @return array<string, class-string> */
    public static function all(): array
    {
        return self::$handlers;
    }
}
