<?php

namespace App\Domain\Bookings;

use App\Models\Catalog\AvailabilityRange;
use App\Models\Catalog\Product;
use App\Services\Crm\CrmClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * BookingEngine (spec 16 §8.1): fachada única de disponibilidad, pasajeros y precio para sitio, widget, B2B,
 * cotizador y reservas. Mismo algoritmo que `proto/assets/js/booking-engine.js`.
 */
class BookingEngine
{
    public const MAX_DAYS = 92;

    public const NEXT_AVAILABLE_DAYS = 400;

    private const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    public function __construct(
        public readonly RuleResolver $rules,
        public readonly PaxRulesResolver $paxRules,
        private readonly AvailabilityCalculator $calc,
        private readonly PriceCalculator $prices,
        private readonly CrmClock $clock,
    ) {}

    public function today(): string
    {
        return $this->clock->today()->toDateString();
    }

    public function dayStatus(Product $p, CarbonInterface|string $date, int $seats = 0, bool $lock = false): DayStatus
    {
        $d = $date instanceof CarbonInterface ? $date->toDateString() : substr($date, 0, 10);

        return $this->statusFor($p, $d, $seats, $this->calc->window($p, $d, $d, $lock));
    }

    /** @return Collection<int, DayStatus> */
    public function days(Product $p, string $from, string $to, int $seats = 0): Collection
    {
        $w = $this->calc->window($p, $from, $to);
        $out = collect();
        for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $to; $d = $d->addDay()) {
            $out->push($this->statusFor($p, $d->toDateString(), $seats, $w));
        }

        return $out;
    }

    /** `BE.month` @return Collection<int, DayStatus> */
    public function month(Product $p, int $y, int $m, int $seats = 0): Collection
    {
        $first = CarbonImmutable::create($y, $m, 1);

        return $this->days($p, $first->toDateString(), $first->endOfMonth()->toDateString(), $seats);
    }

    public function nextAvailable(Product $p, int $seats = 0, ?CarbonInterface $from = null): ?string
    {
        $start = CarbonImmutable::parse(max($this->today(), $from?->toDateString() ?? $this->today()));
        $limit = $start->addDays(self::NEXT_AVAILABLE_DAYS);
        for ($a = $start; $a <= $limit; $a = $a->addDays(31)) {
            $b = min($a->addDays(30)->toDateString(), $limit->toDateString());
            $hit = $this->days($p, $a->toDateString(), $b, $seats)->first(fn (DayStatus $s) => $s->bookable($seats));
            if ($hit) {
                return $hit->date;
            }
        }

        return null;
    }

    /**
     * Leyenda textual (§11 «Leyenda»): segmentos por estado y capacidad desde hoy, solo sobre fechas con rango.
     * «Disponible del 02 al 06 oct (12 cupos/día) · Ocupado del 07 al 09 oct · …».
     */
    public function legend(Product $p, string $from, string $to): string
    {
        if (! $this->rules->on('R-AVAIL-RANGES', $p)) {
            return '';
        }
        $from = max($from, $this->today());
        if ($from > $to) {
            return '';
        }
        $ranges = AvailabilityRange::where('product_id', $p->id)->where('date_from', '<=', $to)->where('date_to', '>=', $from)->orderBy('date_from')->orderBy('code')->get();
        $segments = [];
        $current = null;
        for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $to; $d = $d->addDay()) {
            $range = $this->calc->effectiveRange($ranges, $d->toDateString());
            $key = $range ? $range->status.':'.$range->capacity_per_day : null;
            if ($current && $key === $current['key'] && $current['to']->addDay()->equalTo($d)) {
                $current['to'] = $d;

                continue;
            }
            if ($current) {
                $segments[] = $current;
            }
            $current = $key ? ['key' => $key, 'status' => $range->status, 'cap' => $range->capacity_per_day, 'from' => $d, 'to' => $d] : null;
        }
        if ($current) {
            $segments[] = $current;
        }

        return implode(' · ', array_map(function ($s) {
            $word = ['disponible' => 'Disponible', 'cerrado' => 'Ocupado', 'agotado' => 'Agotado'][$s['status']];

            return $word.' '.self::span($s['from'], $s['to']).($s['status'] === 'disponible' ? " ({$s['cap']} cupos/día)" : '');
        }, $segments));
    }

    /** «del 02 al 06 oct», «del 28 oct al 03 nov», «el 12 oct». */
    public static function span(CarbonInterface $a, CarbonInterface $b): string
    {
        $fmt = fn (CarbonInterface $d, bool $month = true) => $d->format('d').($month ? ' '.self::MONTHS[$d->month - 1] : '');
        if ($a->equalTo($b)) {
            return 'el '.$fmt($a);
        }

        return 'del '.$fmt($a, $a->month !== $b->month || $a->year !== $b->year).' al '.$fmt($b);
    }

    /** `BE.ageType`: ADT | CHD | INF | NA (edad sin banda, CAT-PX-01). */
    public function ageType(Product $p, ?int $age, ?PaxRules $r = null): string
    {
        $r ??= $this->paxRules->resolve($p);

        return match (true) {
            $age === null || $age < 0 => 'NA',
            $age <= $r->infantMaxAge => 'INF',
            $age >= $r->childMinAge && $age <= $r->childMaxAge => 'CHD',
            $age >= $r->adultMinAge => 'ADT',
            default => 'NA',
        };
    }

    /**
     * `BE.validatePax` (§5.1): mínimos y máximos de pasajeros con las reglas efectivas.
     *
     * @return array{ok: bool, errors: list<array{code: string, rule: string, message: string}>, ages: list<array{age: int, type: string}>, rules: array}
     */
    public function validatePax(Product $p, int $adt, int $chd = 0, int $inf = 0, array $ages = []): array
    {
        $r = $this->paxRules->resolve($p);
        $errors = [];
        $err = function (string $rule, string $message) use (&$errors) {
            $errors[] = ['code' => 'PAX', 'rule' => $rule, 'message' => $message];
        };
        if ($adt + $chd + $inf === 0) {
            $err('R-MIN-ADULTS', 'Indique al menos un pasajero.');
        }
        if ($adt < $r->minAdults) {
            $err('R-MIN-ADULTS', "Se requiere al menos {$r->minAdults} adulto(s) por reserva.");
        }
        if ($r->maxChildrenPerAdult !== null && $chd > $adt * $r->maxChildrenPerAdult) {
            $err('R-CHILD-PER-ADULT', "Máximo {$r->maxChildrenPerAdult} niño(s) por adulto.");
        }
        if ($inf > 0 && ! $r->infantAllowed) {
            $err('R-INFANT-FREE', 'Este producto no admite infantes.');
        } elseif ($r->maxInfantsPerAdult !== null && $inf > $adt * $r->maxInfantsPerAdult) {
            $err('R-INFANT-FREE', "Máximo {$r->maxInfantsPerAdult} infante(s) por adulto.");
        }
        $max = (int) $p->maximum_passengers;
        if ($this->rules->on('R-MAX-PAX', $p) && $max > 0 && $adt + $chd + $inf > $max) {
            $err('R-MAX-PAX', "Máximo {$max} pasajeros por reserva.");
        }
        $classified = [];
        foreach ($ages as $age) {
            $type = $this->ageType($p, (int) $age, $r);
            $classified[] = ['age' => (int) $age, 'type' => $type];
            if ($type === 'NA') {
                $err('CAT-PX-01', "La edad {$age} no corresponde a ninguna banda de pasajero.");
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors, 'ages' => $classified, 'rules' => $r->toArray()];
    }

    /** `BE.quote` (§8.1, 16 pasos). */
    public function quote(Product $p, QuoteSelection $sel): QuoteResult
    {
        return $this->prices->quote($this, $p, $sel);
    }

    /** Reglas activas que el motor evaluó para el producto (`rulesApplied`). @return list<string> */
    public function rulesApplied(Product $p): array
    {
        return array_values(array_filter(RuleCatalog::keys(), fn ($k) => $this->rules->on($k, $p)));
    }

    /** Horas de anticipación efectivas (R-CUTOFF): producto → mínimo en días de la ficha × 24 → parámetro del tenant. */
    public function cutoffHours(Product $p): int
    {
        if (! $this->rules->on('R-CUTOFF', $p)) {
            return 0;
        }
        if ($p->cutoff_hours !== null) {
            return (int) $p->cutoff_hours;
        }

        return (int) ($p->booking_advance_min_days * 24 ?: $this->rules->param('R-CUTOFF', 'defaultHours', $p, 24));
    }

    /** §8.1 «Algoritmo de disponibilidad», pasos 1–11. */
    private function statusFor(Product $p, string $date, int $seats, array $w): DayStatus
    {
        $avOn = $this->rules->on('R-AVAIL-RANGES', $p);
        $av = $avOn && $w['has_ranges'];
        $today = $this->today();
        if ($date < $today) {
            return new DayStatus($date, 'pasado', DayStatus::LABELS['pasado']);
        }
        $dep = $w['departures'][$date] ?? null;
        if (! $av && ! $dep) {
            return $w['has_ranges'] && ! $avOn
                ? new DayStatus($date, 'disponible', 'Disponible (sin control de cupos)')
                : new DayStatus($date, 'sin_configurar', DayStatus::LABELS['sin_configurar']);
        }
        $cutoff = $this->cutoffHours($p);
        $diffDays = (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse($date));
        if ($cutoff > 0 && $diffDays * 24 < $cutoff) {
            return new DayStatus($date, 'fuera_de_plazo', "Fuera de plazo (mín. {$cutoff} h)");
        }
        $weekdays = array_map('intval', (array) ($p->operating_weekdays ?? [0, 1, 2, 3, 4, 5, 6]));
        if ($av && $this->rules->on('R-WEEKDAYS', $p) && ! in_array(CarbonImmutable::parse($date)->dayOfWeek, $weekdays, true)) {
            return new DayStatus($date, 'no_opera', DayStatus::LABELS['no_opera']);
        }
        $range = $av ? $this->calc->effectiveRange($w['ranges'], $date) : null;
        if ($range && $range->status !== 'disponible') {
            return new DayStatus($date, $range->status, DayStatus::LABELS[$range->status], $range->note, 0, 0, range: $range->code);
        }
        $cap = $dep ? $dep->capacity : $range?->capacity_per_day;
        if ($cap === null) {
            return $w['has_ranges']
                ? new DayStatus($date, 'cerrado', 'Sin salida programada')
                : new DayStatus($date, 'sin_configurar', DayStatus::LABELS['sin_configurar']);
        }
        $sold = $dep ? $dep->sold : ($w['sold'][$date] ?? 0);
        $held = $dep ? ($w['dep_held'][$dep->id] ?? 0) : ($w['held'][$date] ?? 0);
        $left = max(0, $cap - $sold - $held);
        $extra = ['cap' => $cap, 'sold' => $sold, 'held' => $held, 'range' => $range?->code, 'departure' => $dep?->ref, 'note' => $range?->note];
        if ($left === 0) {
            return new DayStatus($date, 'agotado', DayStatus::LABELS['agotado'], ...$extra + ['left' => 0]);
        }
        if ($seats > $left) {
            return new DayStatus($date, 'sin_cupo', "Solo {$left} cupo(s)", ...$extra + ['left' => $left]);
        }

        return new DayStatus($date, 'disponible', $left <= 4 ? "Últimos {$left} cupos" : DayStatus::LABELS['disponible'], ...$extra + ['left' => $left]);
    }
}
