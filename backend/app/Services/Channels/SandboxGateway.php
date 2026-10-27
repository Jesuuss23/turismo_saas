<?php

namespace App\Services\Channels;

use App\Exceptions\ApiException;
use Illuminate\Support\Str;

/**
 * Pasarela sandbox hasta definir INT-02 (spec 08 §12-11, ECO-BR-16): 4111…/5555… aprobadas, 4000…0002 rechazada (código 51).
 * Nunca se guarda PAN ni CVV: solo últimos 4 dígitos y el id de transacción.
 */
class SandboxGateway
{
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
        $declined = str_starts_with($number, '4000') && str_ends_with($number, '0002');

        return [
            'txn_id' => 'SBX-'.Str::upper(Str::random(16)), 'result' => $declined ? 'rechazado' : 'aprobado', 'last4' => substr($number, -4),
            'code' => $declined ? '51' : '00', 'message' => $declined ? 'Fondos insuficientes (código 51)' : 'Autorización '.random_int(100000, 999999),
        ];
    }
}
