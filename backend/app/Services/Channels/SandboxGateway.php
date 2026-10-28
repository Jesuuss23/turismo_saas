<?php

namespace App\Services\Channels;

use App\Exceptions\ApiException;
use Illuminate\Support\Str;

/**
 * Pasarela sandbox hasta definir INT-02 (spec 08 §12-11, ECO-BR-16; spec 15 SIT-BR-16): 4111…, 5555…, 3782 8224 6310 005 y 3622 7206 2716 67 aprueban;
 * 4000…0002 rechaza (código 51); cualquier otra tarjeta rechaza con código 14. Nunca se guarda PAN ni CVV: solo últimos 4 dígitos y el id de transacción.
 */
class SandboxGateway
{
    public const APPROVED = ['4111111111111111', '5555555555554444', '378282246310005', '36227206271667'];

    /** @return array{txn_id: string, result: string, last4: string, code: string, message: string} */
    public function charge(array $card): array
    {
        $number = preg_replace('/\D/', '', (string) ($card['number'] ?? ''));
        if (strlen($number) < 13 || strlen($number) > 19) {
            throw ApiException::invalid('number', 'Número de tarjeta inválido.');
        }
        if (! preg_match('/^\d{2}\/\d{2}$/', (string) ($card['expiry'] ?? '')) || ! preg_match('/^\d{3,4}$/', (string) ($card['cvv'] ?? ''))) {
            throw ApiException::invalid('expiry', 'Revise vencimiento (MM/AA) y CVV.');
        }
        $approved = in_array($number, self::APPROVED, true) || str_starts_with($number, '4111') || str_starts_with($number, '5555');
        $code = $approved ? '00' : (str_starts_with($number, '4000') && str_ends_with($number, '0002') ? '51' : '14');

        return [
            'txn_id' => 'SBX-'.Str::upper(Str::random(16)), 'result' => $approved ? 'aprobado' : 'rechazado', 'last4' => substr($number, -4), 'code' => $code,
            'message' => match ($code) {
                '00' => 'Autorización '.random_int(100000, 999999), '51' => 'Fondos insuficientes (código 51)', default => 'Tarjeta no válida (código 14)'
            },
        ];
    }
}
