<?php

namespace App\Services\Crm;

use App\Models\Sys\Holiday;
use Illuminate\Support\Carbon;

/** Días hábiles (spec 12 LR-BR-02): sin sábados, domingos ni feriados (nacionales + del tenant). */
class BusinessDayCalculator
{
    /** Fecha límite: `$days` días hábiles contados desde el día siguiente a `$from`. */
    public function addBusinessDays(Carbon $from, int $days): Carbon
    {
        $holidays = $this->holidays();
        $date = Carbon::parse($from->toDateString());
        $left = $days;
        while ($left > 0) {
            $date->addDay();
            if ($this->isBusinessDay($date, $holidays)) {
                $left--;
            }
        }

        return $date;
    }

    /** Días hábiles desde el día siguiente a `$from` hasta `$to` inclusive (0 si `$to` <= `$from`). */
    public function between(Carbon $from, Carbon $to): int
    {
        $holidays = $this->holidays();
        $date = Carbon::parse($from->toDateString());
        $end = Carbon::parse($to->toDateString());
        $n = 0;
        while ($date->lt($end)) {
            $date->addDay();
            if ($this->isBusinessDay($date, $holidays)) {
                $n++;
            }
        }

        return $n;
    }

    public function isBusinessDay(Carbon $date, ?array $holidays = null): bool
    {
        return ! $date->isWeekend() && ! isset(($holidays ?? $this->holidays())[$date->toDateString()]);
    }

    /** @return array<string, true> feriados visibles para el tenant (la RLS agrega los nacionales). */
    private function holidays(): array
    {
        return Holiday::pluck('date')->mapWithKeys(fn ($d) => [Carbon::parse($d)->toDateString() => true])->all();
    }
}
