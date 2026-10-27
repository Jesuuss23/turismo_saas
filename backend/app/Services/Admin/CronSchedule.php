<?php

namespace App\Services\Admin;

use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Support\Carbon;

/** Próxima ejecución de una expresión cron (JOB-BR-02: reanudar recalcula). */
final class CronSchedule
{
    public static function next(string $cron, ?CarbonInterface $from = null): Carbon
    {
        return Carbon::instance((new CronExpression($cron))->getNextRunDate(($from ?? now())->toDateTime(), 0, false, config('app.timezone')));
    }

    /** Frecuencias del modal “Programar envío” (EXP-BR-03) → [cron, etiqueta]. */
    public static function forReport(string $frequency, string $time): array
    {
        [$h, $m] = array_map('intval', explode(':', $time) + [1 => 0]);
        $hm = sprintf('%02d:%02d', $h, $m);

        return match ($frequency) {
            'diario' => ["{$m} {$h} * * *", "diario {$hm}"],
            'lunes' => ["{$m} {$h} * * 1", "lunes {$hm}"],
            'viernes' => ["{$m} {$h} * * 5", "viernes {$hm}"],
            default => ["{$m} {$h} 1 * *", "mensual día 1 {$hm}"],
        };
    }
}
