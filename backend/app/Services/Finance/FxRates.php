<?php

namespace App\Services\Finance;

use App\Models\Finance\FxRate;
use App\Services\Crm\CrmClock;
use App\Tenancy\TenantContext;

/**
 * Tipo de cambio (RT-15, §3.19): el del día sale de `fin_fx_rates` (venta, último registrado hasta la fecha) y, si no hay,
 * del valor del tenant (`settings.fx`). Los documentos conservan el suyo: consolidar en PEN usa siempre el TC del documento.
 */
class FxRates
{
    public function __construct(private readonly CrmClock $clock, private readonly TenantContext $tenant) {}

    public function rate(string $currency, ?string $date = null): string
    {
        if ($currency === 'PEN') {
            return '1.000000';
        }
        $row = FxRate::where('currency', $currency)->whereDate('rate_date', '<=', $date ?? $this->clock->today()->toDateString())->orderByDesc('rate_date')->first();

        return number_format($row ? (float) $row->sell : (float) ($this->tenant->tenant()?->fxToPen($currency) ?? 3.75), 6, '.', '');
    }

    /** TC del documento o, si no lo tiene, el del día (fallback de RT-15). */
    public function rateOf(string $currency, mixed $fx): string
    {
        return $currency === 'PEN' ? '1.000000' : ($fx !== null && (float) $fx > 0 ? number_format((float) $fx, 6, '.', '') : $this->rate($currency));
    }

    public function toPen(mixed $amount, string $currency, mixed $fx = null): float
    {
        return Fin::r2((float) $amount * (float) $this->rateOf($currency, $fx));
    }
}
