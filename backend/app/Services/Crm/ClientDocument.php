<?php

namespace App\Services\Crm;

use App\Exceptions\ApiException;

/** CRM-BR-15 / RT-10: validación documental temprana del cliente. */
final class ClientDocument
{
    public const TYPES = ['DNI', 'RUC', 'PASAPORTE', 'CE'];

    public static function normalize(?string $number): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $number));
    }

    /** Devuelve el mensaje de error o null si es válido. */
    public static function error(string $kind, ?string $country, string $type, string $number): ?string
    {
        return match (true) {
            $number === '' => 'Ingrese el número de documento',
            $kind === 'empresa' && ($country ?? 'Perú') === 'Perú' && $type !== 'RUC' => 'RT-10: una empresa peruana debe registrarse con RUC',
            $type === 'DNI' && ! preg_match('/^\d{8}$/', $number) => 'El DNI debe tener 8 dígitos',
            $type === 'RUC' && ! preg_match('/^(10|15|17|20)\d{9}$/', $number) => 'El RUC debe tener 11 dígitos e iniciar en 10, 15, 17 o 20',
            default => null,
        };
    }

    public static function assertValid(string $kind, ?string $country, string $type, string $number, string $prefix = ''): void
    {
        if ($error = self::error($kind, $country, $type, $number)) {
            throw ApiException::invalid($prefix.'doc_number', $error);
        }
    }
}
