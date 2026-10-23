<?php

namespace App\Services;

use Illuminate\Support\Str;

/** Id de correlación de la solicitud = header X-Request-Id (§9). Se genera si falta. */
class CorrelationId
{
    private ?string $id = null;

    public function set(?string $incoming): string
    {
        $this->id = $incoming !== null && preg_match('/^[A-Za-z0-9._:-]{1,40}$/', $incoming)
            ? $incoming
            : self::generate();

        return $this->id;
    }

    public function get(): string
    {
        return $this->id ??= self::generate();
    }

    public static function generate(): string
    {
        return 'c-'.Str::lower(Str::random(12));
    }
}
