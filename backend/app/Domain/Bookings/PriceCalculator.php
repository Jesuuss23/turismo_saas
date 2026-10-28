<?php

namespace App\Domain\Bookings;

use App\Exceptions\ApiException;
use App\Models\Catalog\AccommodationLeg;
use App\Models\Catalog\AccommodationRoomType;
use App\Models\Catalog\ConfigOption;
use App\Models\Catalog\PaymentPolicy;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductAddon;
use App\Models\Catalog\ProductPromotion;
use App\Models\Catalog\Rate;
use App\Models\Experience\Currency;
use App\Services\Channels\EcomPricing;
use App\Services\Crm\CrmClock;
use App\Services\Quotes\QuotePricing;
use App\Services\Site\SitePricing;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Precio del motor (spec 16 §8.1 «Algoritmo de precio y orden de aplicación»): base por tipo de pasajero, temporada,
 * adicionales, alojamiento, suplemento simple, ajustes sobre el subtotal (no en cascada), recargo por tarjeta y pago.
 * Aritmética decimal con escala 2 y HALF_UP (`round2` del prototipo).
 */
class PriceCalculator
{
    public const TENANT_PAYMENT_MODES = ['total', 'adelanto'];

    /** @var array<string, string> TC por moneda (memo de la petición). */
    private array $rates = [];

    public function __construct(
        private readonly SitePricing $site,
        private readonly EcomPricing $ecom,
        private readonly CrmClock $clock,
    ) {}

    public function quote(BookingEngine $engine, Product $p, QuoteSelection $sel): QuoteResult
    {
        $rules = $engine->rules;
        $on = fn (string $key) => $rules->on($key, $p);
        $r = $engine->paxRules->resolve($p);
        $today = $this->clock->today()->toDateString();
        $warnings = [];
        $warn = function (string $code, string $message, bool $blocking = false) use (&$warnings) {
            $warnings[] = ['code' => $code, 'message' => $message, 'blocking' => $blocking];
        };

        // 1. pasajeros
        foreach ($engine->validatePax($p, $sel->adt, $sel->chd, $sel->inf, $sel->ages)['errors'] as $e) {
            $warn($e['rule'], $e['message'], true);
        }
        // 2. temporada
        $season = $sel->date && $on('R-SEASON') ? $this->site->season($sel->date) : null;
        $factor = BigDecimal::of($season ? (string) $season->factor : '1');
        // 3. tarifa base del canal (o Directo)
        [$adultRate, $childRate] = $this->rates($p, $sel->date ?? $today, $sel->channel);
        if (! $adultRate) {
            $warn('PRD-BR-005', 'El producto no tiene tarifa vigente para la fecha elegida.', true);
        }
        $rateCur = $adultRate?->currency ?? 'USD';
        $cur = $this->currency($sel->currency, $rateCur, $on('R-MULTICURRENCY'));
        $fx = $this->ratePen($cur === 'PEN' ? $rateCur : $cur);
        $margin = BigDecimal::of((string) ($on('R-MULTICURRENCY') ? $rules->param('R-MULTICURRENCY', 'fxMarginPct', null, 0) : 0));
        // FX-03 (spec 17): TC al cliente = TC × (1 − margen) al mostrar en moneda extranjera; hacia soles se usa el TC sin margen.
        $conv = function (BigDecimal $amount, string $from) use ($cur, $margin): BigDecimal {
            if ($from === $cur) {
                return $amount->toScale(2, RoundingMode::HalfUp);
            }
            $pen = $amount->multipliedBy($this->ratePen($from));
            if ($cur === 'PEN') {
                return $pen->toScale(2, RoundingMode::HalfUp);
            }
            $clientRate = BigDecimal::of($this->ratePen($cur))->multipliedBy(BigDecimal::one()->minus($margin->dividedBy(100, 6, RoundingMode::HalfUp)));

            return $pen->dividedBy($clientRate, 2, RoundingMode::HalfUp);
        };
        $round = fn (BigDecimal $v) => $v->toScale(2, RoundingMode::HalfUp);

        // 4–5. unitarios (en la moneda de la tarifa)
        $delta = BigDecimal::zero();
        $optionLabels = [];
        if ($sel->options) {
            foreach (ConfigOption::whereIn('id', array_filter($sel->options, fn ($id) => preg_match('/^[0-9a-f-]{36}$/i', $id)))->get() as $o) {
                $delta = $delta->plus((string) $o->price_delta);
                $optionLabels[] = $o->label;
            }
        }
        $adtUnit = $adultRate ? $round(BigDecimal::of((string) $adultRate->price)->plus($delta)->multipliedBy($factor)) : BigDecimal::zero();
        $chdUnit = BigDecimal::zero();
        if ($r->childPays) {
            // CAT-PX-03: la tarifa Niño vigente prevalece; el % es respaldo.
            $chdUnit = $childRate
                ? $round(BigDecimal::of((string) $childRate->price)->multipliedBy($factor))
                : $adtUnit->multipliedBy($r->childPricePct)->dividedBy(100, 2, RoundingMode::HalfUp);
        }
        $infUnit = $r->infantPays ? $chdUnit : BigDecimal::zero();

        // 6. líneas base (no removibles)
        $lines = [];
        $line = function (string $key, string $kind, string $label, int $qty, BigDecimal $unit, bool $removable = false, array $meta = []) use (&$lines, $sel) {
            $lines[] = ['key' => $key, 'kind' => $kind, 'label' => $label, 'qty' => $qty, 'unit' => (string) $unit->toScale(2),
                'amount' => (string) $unit->multipliedBy($qty)->toScale(2, RoundingMode::HalfUp), 'removable' => $removable,
                'removed' => $removable && in_array($key, $sel->removed, true), 'meta' => $meta];
        };
        foreach ([['ADT', 'Adulto', $sel->adt, $adtUnit, "{$r->adultMinAge}+ años"], ['CHD', 'Niño', $sel->chd, $chdUnit, "{$r->childMinAge}–{$r->childMaxAge} años"],
            ['INF', 'Infante', $sel->inf, $infUnit, "0–{$r->infantMaxAge} años"]] as [$type, $label, $qty, $unit, $band]) {
            if ($qty > 0) {
                $line("base:{$type}", 'base', "{$label} ({$band})", $qty, $conv($unit, $rateCur), false, ['pax_type' => $type, 'rate_currency' => $rateCur,
                    'source' => $type === 'CHD' ? ($childRate && $r->childPays ? 'tarifa_nino' : "{$r->childPricePct} %") : null]);
            }
        }
        if ($optionLabels) {
            $line('info:options', 'info', 'Personalización: '.implode(', ', $optionLabels), 0, BigDecimal::zero());
        }
        // 7. temporada (informativa)
        if ($season && ! $factor->isEqualTo(1)) {
            $line('info:season', 'info', "Temporada {$season->name} ×".rtrim(rtrim((string) $factor->toScale(2), '0'), '.'), 0, BigDecimal::zero());
        }

        $removable = $on('R-PREVIEW-REMOVE');
        // 8. adicionales
        if ($sel->addons && ! $on('R-ADDONS')) {
            $warn('R-ADDONS', 'Los adicionales no están disponibles para este producto.');
        } elseif ($sel->addons) {
            $addons = ProductAddon::where('product_id', $p->id)->whereIn('id', array_filter(array_column($sel->addons, 'id'), fn ($id) => preg_match('/^[0-9a-f-]{36}$/i', $id)))->get()->keyBy('id');
            foreach ($sel->addons as $a) {
                $addon = $addons[$a['id']] ?? null;
                if (! $addon || $a['qty'] < 1) {
                    $warn('R-ADDONS', 'Adicional no disponible.');

                    continue;
                }
                $line("addon:{$addon->id}", 'addon', $addon->name.($addon->charge_per === 'pax' ? ' (por pasajero)' : ' (por reserva)'), $a['qty'],
                    $conv(BigDecimal::of((string) $addon->price), $addon->currency), $removable, ['code' => $addon->code, 'category' => $addon->category]);
            }
        }

        // 9. alojamiento
        $rooms = 0;
        $nights = 0;
        $lodgingTotal = BigDecimal::zero();
        $lodgingOn = $on('R-LODGING') && $p->accommodation_enabled;
        if ($sel->lodging && ! $lodgingOn) {
            $warn('R-LODGING', 'El alojamiento no está disponible para este producto.');
        } elseif ($lodgingOn) {
            $legs = AccommodationLeg::where('product_id', $p->id)->with('hotels.roomTypes')->orderBy('sequence')->get()->keyBy('id');
            $accCur = $p->accommodation_currency ?: $rateCur;
            $chosen = [];
            foreach ($sel->lodging as $l) {
                $leg = $legs[$l['leg_id']] ?? null;
                $hotel = $leg?->hotels->firstWhere('id', $l['hotel_id']);
                if (! $leg || ! $hotel) {
                    $warn('R-LODGING', 'Hotel no disponible para el tramo elegido.');

                    continue;
                }
                $beds = 0;
                $legRooms = 0;
                $legRemoved = true;
                foreach ($l['rooms'] as $room) {
                    /** @var AccommodationRoomType|null $type */
                    $type = $hotel->roomTypes->firstWhere('room_type', $room['type']);
                    if (! $type || $room['qty'] < 1) {
                        continue;
                    }
                    $key = "lodging:{$leg->id}:{$type->room_type}";
                    $unit = $conv(BigDecimal::of((string) $type->price_per_night)->multipliedBy($leg->nights), $accCur);
                    $line($key, 'lodging', "{$leg->city} · {$hotel->name} · {$type->room_type} × {$leg->nights} noche(s)", $room['qty'], $unit, $removable,
                        ['leg' => $leg->code, 'city' => $leg->city, 'hotel' => $hotel->name, 'room_type' => $type->room_type, 'nights' => $leg->nights, 'capacity' => $type->capacity,
                            'price_per_night' => (string) $type->price_per_night]);
                    if (! in_array($key, $sel->removed, true) || ! $removable) {
                        $legRemoved = false;
                        $beds += $type->capacity * $room['qty'];
                        $legRooms += $room['qty'];
                        $lodgingTotal = $lodgingTotal->plus($unit->multipliedBy($room['qty']));
                    }
                }
                if (! $legRemoved) {
                    $rooms += $legRooms;
                    $nights += $leg->nights;
                    $chosen[$leg->id] = true;
                    // CAT-LG-03 / R-LODGING-CAPACITY: infantes comparten cama.
                    if ($on('R-LODGING-CAPACITY') && $beds < $sel->adt + $sel->chd) {
                        $warn('R-LODGING-CAPACITY', "{$leg->city}: las habitaciones elegidas tienen {$beds} cama(s) para ".($sel->adt + $sel->chd).' pasajero(s).', true);
                    }
                }
            }
            if (! $p->accommodation_optional && $legs->isNotEmpty() && count($chosen) < $legs->count()) {
                $warn('R-LODGING', 'El alojamiento está incluido: elija hotel y habitaciones en cada ciudad.', true);
            }
        }

        // 10. suplemento simple
        if ($on('R-SINGLE-SUPP')) {
            $pct = (string) $rules->param('R-SINGLE-SUPP', 'pct', $p, 0);
            foreach ($lines as $l) {
                if ($l['kind'] === 'lodging' && ! $l['removed'] && $l['meta']['room_type'] === 'Simple') {
                    $amount = BigDecimal::of($l['amount'])->multipliedBy($pct)->dividedBy(100, 2, RoundingMode::HalfUp);
                    $line('supp:'.substr($l['key'], 8), 'supplement', "Suplemento simple {$pct} % · {$l['meta']['city']}", 1, $amount, false);
                    $lodgingTotal = $lodgingTotal->plus($amount);
                }
            }
        }

        // 11. subtotal
        $subtotal = BigDecimal::zero();
        foreach ($lines as $l) {
            if (! $l['removed']) {
                $subtotal = $subtotal->plus($l['amount']);
            }
        }
        $subtotal = $round($subtotal);

        // 12. ajustes sobre el mismo subtotal
        $adjustments = [];
        $adjust = function (string $key, string $label, BigDecimal $amount) use (&$adjustments) {
            $adjustments[] = ['key' => $key, 'label' => $label, 'amount' => (string) $amount->toScale(2, RoundingMode::HalfUp)];
        };
        $pctOf = fn ($pct) => $subtotal->multipliedBy((string) $pct)->dividedBy(100, 2, RoundingMode::HalfUp);
        $days = $sel->date ? (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse($sel->date), false) : null;
        if ($on('R-GROUP-DISC')) {
            $g = $rules->params('R-GROUP-DISC', $p);
            $payers = $sel->adt + ($r->childPays ? $sel->chd : 0);
            if ($payers >= (int) ($g['fromPax'] ?? PHP_INT_MAX)) {
                $adjust('R-GROUP-DISC', "Descuento por grupo ({$g['pct']} %)", $pctOf($g['pct'])->negated());
            }
        }
        if ($days !== null && $on('R-EARLY-BIRD')) {
            $g = $rules->params('R-EARLY-BIRD', $p);
            if ($days >= (int) ($g['days'] ?? PHP_INT_MAX)) {
                $adjust('R-EARLY-BIRD', "Compra anticipada ({$g['pct']} %)", $pctOf($g['pct'])->negated());
            }
        }
        if ($days !== null && $on('R-LAST-MINUTE')) {
            $g = $rules->params('R-LAST-MINUTE', $p);
            if ($days < (int) ($g['days'] ?? 0)) {
                $adjust('R-LAST-MINUTE', "Último minuto (+{$g['pct']} %)", $pctOf($g['pct']));
            }
        }
        if ($sel->promoCode) {
            $promo = $on('R-PROMO') ? $this->promotion($p, $sel->promoCode, $sel->adt + $sel->chd) : null;
            if (! $promo) {
                $warn('R-PROMO', 'Código no válido para este producto o fuera de vigencia.');
            } elseif (in_array($promo->kind, ProductPromotion::PERCENT_KINDS, true)) {
                $adjust('R-PROMO', "{$promo->name} (".EcomPricing::percent($promo).')', $pctOf((string) $promo->value)->negated());
            } else {
                $fixed = $conv(BigDecimal::of((string) $promo->value), $rateCur);
                $adjust('R-PROMO', $promo->name, ($fixed->isGreaterThan($subtotal) ? $subtotal : $fixed)->negated());
            }
        }
        // 13. total y recargo por tarjeta
        $total = $subtotal;
        foreach ($adjustments as $a) {
            $total = $total->plus($a['amount']);
        }
        if ($sel->paymentMethod === 'tarjeta' && $on('R-PAY-FEE')) {
            $feePct = (string) $rules->param('R-PAY-FEE', 'pct', null, 0);
            $fee = $total->multipliedBy($feePct)->dividedBy(100, 2, RoundingMode::HalfUp);
            $adjust('R-PAY-FEE', "Recargo por tarjeta ({$feePct} %)", $fee);
            $total = $total->plus($fee);
        }
        $total = $round(BigDecimal::max($total, BigDecimal::zero()));

        // 14–15. modalidades y pago
        $policy = PaymentPolicy::where('product_id', $p->id)->first();
        $modes = array_values(array_intersect(self::TENANT_PAYMENT_MODES, (array) ($policy?->modes ?: self::TENANT_PAYMENT_MODES)));
        if (! $on('R-DEPOSIT')) {
            $modes = array_values(array_diff($modes, ['adelanto']));
        }
        if ($on('R-PAY-LATER')) {
            $modes[] = 'pagar_despues';
        }
        $modes = $modes ?: ['total'];
        $mode = $sel->paymentMode && in_array($sel->paymentMode, $modes, true) ? $sel->paymentMode : $modes[0];
        if ($sel->paymentMode && $sel->paymentMode !== $mode) {
            $warn('PAYMENT_MODE', 'La modalidad de pago elegida no está disponible para este producto.', true);
        }
        $pct = match ($mode) {
            'adelanto' => (string) ($policy?->deposit_pct ?? $rules->param('R-DEPOSIT', 'pct', null, 30)),
            'pagar_despues' => '0',
            default => '100',
        };
        $deposit = $total->multipliedBy($pct)->dividedBy(100, 2, RoundingMode::HalfUp);
        $balanceDue = null;
        if ($mode !== 'total' && $sel->date) {
            $dueDays = (int) ($policy?->balance_due_days ?? $rules->param('R-DEPOSIT', 'balanceDueDays', null, 30));
            $balanceDue = CarbonImmutable::parse($sel->date)->subDays($dueDays)->toDateString();
            if ($balanceDue < $today) {
                $balanceDue = $today;
                $warn('CAT-PY-02', 'El saldo vence antes de hoy: se cobrará de inmediato.');
            }
        }
        $holdHours = $mode === 'pagar_despues' ? (int) $rules->param('R-PAY-LATER', 'holdHours', $p, 24) : null;

        return new QuoteResult(
            lines: $lines, adjustments: $adjustments, subtotal: (string) $subtotal, total: (string) $total, currency: $cur,
            fxRate: $cur === $rateCur ? '1' : $fx, modes: $modes, paymentMode: $mode, depositPct: str_contains($pct, '.') ? rtrim(rtrim($pct, '0'), '.') : $pct,
            deposit: (string) $deposit, balance: (string) $total->minus($deposit)->toScale(2), balanceDue: $balanceDue, holdHours: $holdHours,
            rooms: $rooms, nights: $nights, lodgingTotal: (string) $round($lodgingTotal), warnings: $warnings, rulesApplied: $engine->rulesApplied($p),
            meta: ['product' => ['id' => $p->id, 'ref' => $p->ref, 'name' => $p->name], 'date' => $sel->date, 'pax' => ['adt' => $sel->adt, 'chd' => $sel->chd, 'inf' => $sel->inf],
                'rate_id' => $adultRate?->id, 'rate_currency' => $rateCur, 'season' => SitePricing::seasonRow($season),
                'units' => ['adt' => (string) $adtUnit, 'chd' => (string) $chdUnit, 'inf' => (string) $infUnit]],
        );
    }

    /**
     * Moneda de la cotización (R-MULTICURRENCY, spec 17): encendida, cualquier moneda activa del tenant; apagada, solo la moneda base
     * (PEN) o la de la tarifa. Otra moneda → 422 CURRENCY_NOT_ALLOWED.
     */
    private function currency(?string $wanted, string $rateCur, bool $multi): string
    {
        if ($wanted === null || $wanted === '' || $wanted === $rateCur) {
            return $rateCur;
        }
        $allowed = $multi ? (Currency::where('active', true)->pluck('code')->map(fn ($c) => trim($c))->all() ?: ['PEN', 'USD']) : ['PEN', $rateCur];
        if (! in_array($wanted, $allowed, true)) {
            throw ApiException::unprocessable('CURRENCY_NOT_ALLOWED', $multi
                ? "La moneda {$wanted} no está activa para este tenant."
                : "Multimoneda (R-MULTICURRENCY) está desactivada: solo se cotiza en PEN o {$rateCur}.");
        }

        return $wanted;
    }

    /** Soles por unidad: USD con el TC del día (`fin_fx_rates`), las demás con el vigente de `fin_currencies`. */
    private function ratePen(string $code): string
    {
        if ($code === 'PEN') {
            return '1';
        }
        if ($code === 'USD') {
            return $this->site->usdRate();
        }

        return $this->rates[$code] ??= (string) (Currency::where('code', $code)->value('rate_pen') ?? throw ApiException::unprocessable('CURRENCY_NOT_ALLOWED', "Moneda {$code} sin tipo de cambio."));
    }

    /** Tarifa vigente: segmento Adulto/Pasajero del canal → Directo; Niño del canal → Directo (PRC-02, CAT-PX-03). @return array{0: ?Rate, 1: ?Rate} */
    public function rates(Product $p, string $date, string $channel): array
    {
        $valid = Rate::where('product_id', $p->id)->orderBy('ref')->get()->filter(fn (Rate $r) => QuotePricing::rateValidOn($r, $date) && ! $r->isNet())->values();
        $adultSeg = fn (Rate $r) => in_array($r->segment, ['Adulto', 'Pasajero'], true);
        $adult = $valid->first(fn (Rate $r) => $r->channel === $channel && $adultSeg($r)) ?? $valid->first(fn (Rate $r) => $r->channel === 'Directo' && $adultSeg($r));
        $child = $valid->first(fn (Rate $r) => $r->segment === 'Niño' && $r->channel === $channel) ?? $valid->first(fn (Rate $r) => $r->segment === 'Niño' && $r->channel === 'Directo');

        return [$adult, $child];
    }

    private function promotion(Product $p, string $code, int $pax): ?ProductPromotion
    {
        return $this->ecom->promotions($p)->first(fn (ProductPromotion $x) => $x->promo_code && strtoupper($x->promo_code) === $code && $pax >= (int) ($x->min_pax ?: 0));
    }
}
