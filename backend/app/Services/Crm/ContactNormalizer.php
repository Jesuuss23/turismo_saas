<?php

namespace App\Services\Crm;

/** Normalización de contacto, idéntica a las columnas generadas `email_norm` / `phone_norm` (CRM-BR-03). */
final class ContactNormalizer
{
    public const EMAIL_PATTERN = '/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/u';

    public static function email(?string $email): ?string
    {
        $email = $email === null ? '' : mb_strtolower(trim($email));

        return $email === '' ? null : $email;
    }

    /** Últimos 9 dígitos si hay al menos 7; si no, null. */
    public static function phone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return strlen($digits) >= 7 ? substr($digits, -9) : null;
    }

    public static function phoneDigits(?string $phone): int
    {
        return strlen(preg_replace('/\D/', '', (string) $phone));
    }
}
