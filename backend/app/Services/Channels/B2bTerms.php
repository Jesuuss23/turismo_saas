<?php

namespace App\Services\Channels;

use App\Models\Catalog\Product;
use App\Models\Catalog\ProductB2bRate;
use App\Models\Catalog\ProductChannel;
use App\Models\Catalog\Rate;
use App\Models\Channel\B2bAgency;
use App\Services\Crm\CrmClock;
use App\Services\Quotes\QuotePricing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * RT-14 / PRD-BR-016 en tres niveles (spec 08 §11-2): agencia (vigencia/estado), producto (ACTIVE + is_b2b + canal B2B),
 * tarifa por agencia (existencia y valid_to). Crédito solo con tarifa que lo permita, línea > 0 y agencia activa (B2B-BR-03).
 */
class B2bTerms
{
    public function __construct(private readonly CrmClock $clock) {}

    /** B2B-BR-01: productos ACTIVE, is_b2b y publicados en el canal B2B. */
    public function authorizedProducts(): Builder
    {
        return Product::where('status', Product::ACTIVE)->where('is_b2b', true)
            ->whereIn('id', ProductChannel::where('channel', 'b2b')->where('is_published', true)->select('product_id'));
    }

    /** Precio público vigente (Directo adulto/pasajero) que se muestra como PVP sugerido. */
    public function publicRate(Product $product, ?string $date = null): ?Rate
    {
        $date ??= $this->clock->today()->toDateString();
        $valid = Rate::where('product_id', $product->id)->orderBy('ref')->get()->filter(fn (Rate $r) => QuotePricing::rateValidOn($r, $date) && ! $r->isNet());

        return $valid->first(fn (Rate $r) => $r->channel === 'Directo' && in_array($r->segment, ['Adulto', 'Pasajero'], true))
            ?? $valid->first(fn (Rate $r) => $r->channel === 'Directo' && $r->segment !== 'Niño')
            ?? $valid->first(fn (Rate $r) => $r->segment !== 'Niño');
    }

    public function rateFor(B2bAgency $agency, Product $product): ?ProductB2bRate
    {
        return ProductB2bRate::where('product_id', $product->id)->where('agency_id', $agency->id)->first();
    }

    /** B2B-BR-02: el convenio permite reservar (ni crédito ni prepago si vencido o inactiva). */
    public function agencyBlocker(B2bAgency $agency): ?string
    {
        return match ($agency->effectiveStatus()) {
            'vencido' => 'Regla 14: convenio vencido el '.self::date($agency->valid_to).'. No es posible reservar.',
            'inactiva' => 'Regla 14: agencia inactiva. No es posible reservar.',
            default => null,
        };
    }

    /** B2B-BR-03. */
    public function creditAllowed(B2bAgency $agency, ?ProductB2bRate $rate): bool
    {
        return (bool) $rate?->is_credit && (float) $agency->credit_limit > 0 && $agency->effectiveStatus() === 'activa';
    }

    /**
     * Términos del producto para la agencia (RT-14).
     *
     * @return array{ok: bool, reason: ?string, rate: ?ProductB2bRate, net: ?string, currency: ?string, pvp: ?string, pvp_currency: ?string, commission_pct: ?string, credit: bool, valid_to: ?string}
     */
    public function terms(B2bAgency $agency, Product $product): array
    {
        $rate = $this->rateFor($agency, $product);
        $public = $this->publicRate($product);
        $reason = $this->agencyBlocker($agency);
        if (! $reason && ! $rate) {
            $reason = 'Sin tarifa B2B asignada a su lista de precios (Regla 14: B2B por agencia/producto/vigencia).';
        }
        if (! $reason && $rate->valid_to && $rate->valid_to->toDateString() < $this->clock->today()->toDateString()) {
            $reason = 'Tarifa B2B vencida el '.self::date($rate->valid_to).' (Regla 14).';
        }

        return [
            'ok' => $reason === null, 'reason' => $reason, 'rate' => $rate,
            'net' => $rate ? (string) $rate->price : null, 'currency' => $rate?->currency,
            'pvp' => $public ? (string) $public->price : null, 'pvp_currency' => $public?->currency,
            'commission_pct' => $rate ? (string) $rate->commission_pct : null,
            'credit' => $reason === null && $this->creditAllowed($agency, $rate),
            'valid_to' => $rate?->valid_to?->toDateString(),
        ];
    }

    public static function date(?Carbon $date): string
    {
        return $date ? $date->locale('es')->translatedFormat('d M Y') : '—';
    }
}
