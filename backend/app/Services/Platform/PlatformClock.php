<?php

namespace App\Services\Platform;

use Illuminate\Support\Carbon;

/** Fecha de negocio de la plataforma (Lima): renovaciones, dunning y trials se calculan por día calendario peruano. */
final class PlatformClock
{
    public const TZ = 'America/Lima';

    public static function now(): Carbon
    {
        return Carbon::now(self::TZ);
    }

    public static function today(): Carbon
    {
        return Carbon::now(self::TZ)->startOfDay();
    }
}
