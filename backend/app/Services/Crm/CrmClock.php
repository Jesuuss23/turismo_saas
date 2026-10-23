<?php

namespace App\Services\Crm;

use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/** "Hoy" y horas de negocio en la zona del tenant (CRM-BR-05, agendas). */
class CrmClock
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function timezone(): string
    {
        return $this->tenant->tenant()?->timezone() ?? 'America/Lima';
    }

    public function now(): Carbon
    {
        return now($this->timezone());
    }

    public function today(): Carbon
    {
        return $this->now()->startOfDay();
    }

    /** Primer contacto: ahora + 2 h, con tope a las 20:00 del día (CRM-BR-08). */
    public function firstContactDue(): Carbon
    {
        $due = $this->now()->addHours(2);
        $cap = $this->now()->setTime(20, 0);

        return $due->greaterThan($cap) ? $cap : $due;
    }

    /** Mañana a las 10:00 (actividad de detractor, CRM-BR-39). */
    public function tomorrowAt(int $hour = 10, int $days = 1): Carbon
    {
        return $this->today()->addDays($days)->setTime($hour, 0);
    }

    public function toPen(float $amount, string $currency): float
    {
        return round($amount * ($this->tenant->tenant()?->fxToPen($currency) ?? 1.0), 2);
    }
}
