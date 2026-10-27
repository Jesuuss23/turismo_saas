<?php

namespace App\Services\Finance;

/** Leyenda de monto en letras del comprobante (Catálogo 52 `1000`): "SON: MIL DOSCIENTOS CON 50/100 SOLES". */
final class AmountInWords
{
    private const UNITS = ['', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE', 'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE',
        'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE', 'VEINTE', 'VEINTIUNO', 'VEINTIDÓS', 'VEINTITRÉS', 'VEINTICUATRO', 'VEINTICINCO',
        'VEINTISÉIS', 'VEINTISIETE', 'VEINTIOCHO', 'VEINTINUEVE'];

    private const TENS = [3 => 'TREINTA', 4 => 'CUARENTA', 5 => 'CINCUENTA', 6 => 'SESENTA', 7 => 'SETENTA', 8 => 'OCHENTA', 9 => 'NOVENTA'];

    private const HUNDREDS = [1 => 'CIENTO', 2 => 'DOSCIENTOS', 3 => 'TRESCIENTOS', 4 => 'CUATROCIENTOS', 5 => 'QUINIENTOS', 6 => 'SEISCIENTOS',
        7 => 'SETECIENTOS', 8 => 'OCHOCIENTOS', 9 => 'NOVECIENTOS'];

    public static function format(float|string $amount, string $currency): string
    {
        $cents = (int) round(Fin::r2($amount) * 100);
        $integer = intdiv($cents, 100);
        $words = $integer === 0 ? 'CERO' : self::words($integer);
        $name = $currency === 'USD' ? 'DÓLARES AMERICANOS' : 'SOLES';

        return sprintf('SON: %s CON %02d/100 %s', $words, $cents % 100, $name);
    }

    private static function words(int $n): string
    {
        if ($n >= 1_000_000) {
            $millions = intdiv($n, 1_000_000);
            $head = $millions === 1 ? 'UN MILLÓN' : self::words($millions).' MILLONES';

            return trim($head.' '.($n % 1_000_000 ? self::words($n % 1_000_000) : ''));
        }
        if ($n >= 1000) {
            $thousands = intdiv($n, 1000);
            $head = $thousands === 1 ? 'MIL' : self::apocope(self::words($thousands)).' MIL';

            return trim($head.' '.($n % 1000 ? self::words($n % 1000) : ''));
        }
        if ($n >= 100) {
            $head = $n === 100 ? 'CIEN' : self::HUNDREDS[intdiv($n, 100)];

            return trim($head.' '.($n % 100 ? self::words($n % 100) : ''));
        }
        if ($n < 30) {
            return self::UNITS[$n];
        }

        return self::TENS[intdiv($n, 10)].($n % 10 ? ' Y '.self::UNITS[$n % 10] : '');
    }

    /** "VEINTIUNO MIL" → "VEINTIÚN MIL", "UNO" → "UN". */
    private static function apocope(string $words): string
    {
        return (string) preg_replace(['/VEINTIUNO$/u', '/UNO$/u'], ['VEINTIÚN', 'UN'], $words);
    }
}
