<?php

namespace App\Support\Database;

/** Conversión de columnas text[] de PostgreSQL. */
final class PgArray
{
    /** @return list<string> */
    public static function decode(?string $value): array
    {
        if ($value === null || $value === '{}') {
            return [];
        }

        return array_map(fn ($v) => stripcslashes($v), str_getcsv(trim($value, '{}'), ',', '"', '\\'));
    }

    /** @param list<string> $values */
    public static function encode(array $values): string
    {
        return '{'.implode(',', array_map(fn ($v) => '"'.addcslashes((string) $v, '"\\').'"', array_values($values))).'}';
    }
}
