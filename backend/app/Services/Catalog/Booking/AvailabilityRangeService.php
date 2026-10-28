<?php

namespace App\Services\Catalog\Booking;

use App\Domain\Bookings\AvailabilityCalculator;
use App\Events\Catalog\AvailabilityClosedWithSales;
use App\Events\Catalog\AvailabilityRangeChanged;
use App\Exceptions\ApiException;
use App\Models\Catalog\AvailabilityRange;
use App\Models\Catalog\Product;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Crm\CrmClock;
use App\Services\SequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Rangos de disponibilidad (spec 16 §3.3, CAT-AV-01..06): alta, edición, baja, cierre rápido de fechas y operación.
 * Las exclusion constraints son la última barrera de CAT-AV-01; aquí se valida antes para devolver los conflictos.
 */
class AvailabilityRangeService
{
    public const MAX_CAPACITY = 500;

    public const MAX_SPAN_DAYS = 730;

    public function __construct(
        private readonly AvailabilityCalculator $calc,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly CrmClock $clock,
    ) {}

    public function create(Product $product, array $data, User $actor, bool $confirmSales = false): AvailabilityRange
    {
        $row = $this->validated($data);

        return $this->persist($product, function () use ($product, $row, $actor, $confirmSales) {
            $this->assertNoOverlap($product, $row);
            $this->assertSales($product, $row, $confirmSales);
            $first = ! AvailabilityRange::where('product_id', $product->id)->withTrashed()->exists();
            $range = AvailabilityRange::create($row + ['product_id' => $product->id, 'code' => $this->sequences->next('availability_range')]);
            if ($first) {
                $this->audit->log('Producto', $product->ref, 'disponibilidad_rangos', null, 'Configuración de disponibilidad por rangos iniciada', actor: $actor);
            }
            $this->audit->log('Producto', $product->ref, 'rango_crear', null, self::label($range), actor: $actor);

            return $range;
        });
    }

    public function update(Product $product, AvailabilityRange $range, array $data, User $actor, bool $confirmSales = false): AvailabilityRange
    {
        $row = $this->validated($data + $range->only(['date_from', 'date_to', 'status', 'capacity_per_day', 'note']), $range);

        return $this->persist($product, function () use ($product, $range, $row, $actor, $confirmSales) {
            $this->assertNoOverlap($product, $row, $range->id);
            $this->assertSales($product, $row, $confirmSales);
            $before = self::label($range);
            $range->fill($row);
            if ($range->isDirty()) {
                $range->save();
                $this->audit->log('Producto', $product->ref, 'rango_modificar', $before, self::label($range), actor: $actor);
            }

            return $range;
        });
    }

    public function delete(Product $product, AvailabilityRange $range, User $actor): void
    {
        DB::transaction(function () use ($product, $range, $actor) {
            $range->delete();
            $this->audit->log('Producto', $product->ref, 'rango_eliminar', self::label($range), null, actor: $actor);
        });
        AvailabilityRangeChanged::dispatch($product->id);
    }

    /**
     * «Cerrar fechas» (§6.2): agrupa fechas contiguas y crea N rangos en una transacción.
     *
     * @return array{dates: int, ranges: list<AvailabilityRange>, message: string}
     */
    public function closeDates(Product $product, array $dates, string $status, ?string $note, User $actor, bool $confirmSales = false): array
    {
        if (! in_array($status, ['cerrado', 'agotado'], true)) {
            throw ApiException::invalid('status', 'El estado debe ser cerrado o agotado.');
        }
        $dates = collect($dates)->map(fn ($d) => $this->date((string) $d, 'dates'))->unique()->sort()->values();
        if ($dates->isEmpty()) {
            throw ApiException::invalid('dates', 'Seleccione al menos una fecha.');
        }
        $today = $this->clock->today()->toDateString();
        if ($dates->first() < $today) {
            throw ApiException::unprocessable('CAT-AV-05', 'No se pueden cerrar fechas pasadas.');
        }
        $groups = [];
        foreach ($dates as $d) {
            $last = count($groups) - 1;
            if ($last >= 0 && CarbonImmutable::parse($groups[$last][1])->addDay()->toDateString() === $d) {
                $groups[$last][1] = $d;
            } else {
                $groups[] = [$d, $d];
            }
        }
        $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 200) : null;
        $rows = array_map(fn ($g) => ['date_from' => $g[0], 'date_to' => $g[1], 'status' => $status, 'capacity_per_day' => 0, 'note' => $note], $groups);

        $ranges = $this->persist($product, function () use ($product, $rows, $actor, $confirmSales, $status, $note) {
            foreach ($rows as $row) {
                $this->assertNoOverlap($product, $row);
            }
            $this->assertSales($product, ['date_from' => $rows[0]['date_from'], 'date_to' => end($rows)['date_to'], 'status' => $status, 'dates' => array_merge(...array_map(
                fn ($r) => self::period($r['date_from'], $r['date_to']), $rows))], $confirmSales);
            $created = [];
            foreach ($rows as $row) {
                $created[] = AvailabilityRange::create($row + ['product_id' => $product->id, 'code' => $this->sequences->next('availability_range')]);
            }
            $this->audit->log('Producto', $product->ref, 'cerrar_fechas', null, "{$status} · ".implode(', ', array_map(fn ($r) => $r->code, $created)).($note ? " · {$note}" : ''), actor: $actor);

            return $created;
        });
        $n = $dates->count();

        return ['dates' => $n, 'ranges' => $ranges, 'message' => "{$n} fecha(s) cerradas en ".count($ranges).' rango(s)'];
    }

    /** CAT-AV-06: al menos un día de operación; anticipación entera 0–8760 h (null = hereda). */
    public function operation(Product $product, array $data, User $actor): Product
    {
        $days = array_values(array_unique(array_map('intval', (array) ($data['operating_weekdays'] ?? []))));
        sort($days);
        if ($days === [] || array_diff($days, range(0, 6))) {
            throw ApiException::unprocessable('CAT-AV-06', 'Seleccione al menos un día de operación (0 = domingo … 6 = sábado).');
        }
        $cutoff = $data['cutoff_hours'] ?? null;
        if ($cutoff !== null && $cutoff !== '' && (! is_numeric($cutoff) || (int) $cutoff != $cutoff || $cutoff < 0 || $cutoff > 8760)) {
            throw ApiException::unprocessable('CAT-AV-06', 'La anticipación debe ser un número entero de horas entre 0 y 8760.');
        }
        $cutoff = $cutoff === null || $cutoff === '' ? null : (int) $cutoff;
        $before = self::operationLabel($product);
        $product->forceFill(['operating_weekdays' => $days, 'cutoff_hours' => $cutoff])->save();
        $this->audit->log('Producto', $product->ref, 'disponibilidad_operacion', $before, self::operationLabel($product), actor: $actor);
        AvailabilityRangeChanged::dispatch($product->id);

        return $product;
    }

    /** Fechas futuras con ventas en un período (para CAT-AV-03/04). @return list<array{date: string, pax: int, reservations: list<string>}> */
    public function salesIn(Product $product, string $from, string $to): array
    {
        $from = max($from, $this->clock->today()->toDateString());
        if ($from > $to) {
            return [];
        }
        $sold = $this->calc->sold($product, $from, $to);
        $codes = DB::table('booking_services as s')->join('booking_reservations as r', 'r.id', '=', 's.reservation_id')
            ->where('s.product_id', $product->id)->whereBetween('s.service_date', [$from, $to])->whereNull('s.departure_id')
            ->whereNotIn('s.status', ['cancelado', 'rechazado'])->whereNotIn('r.status', ['borrador', 'cancelada'])
            ->selectRaw('s.service_date::text AS d, r.code')->distinct()->orderBy('r.code')->get()->groupBy('d');
        $out = [];
        foreach ($sold as $date => $pax) {
            if ($pax > 0) {
                $out[] = ['date' => $date, 'pax' => $pax, 'reservations' => $codes->get($date, collect())->pluck('code')->all()];
            }
        }
        usort($out, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return $out;
    }

    public static function label(AvailabilityRange $r): string
    {
        return "{$r->code} · {$r->date_from->toDateString()} → {$r->date_to->toDateString()} · {$r->status}".($r->status === 'disponible' ? " · {$r->capacity_per_day}/día" : '').($r->note ? " · {$r->note}" : '');
    }

    public static function operationLabel(Product $p): string
    {
        $names = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
        $days = array_map(fn ($d) => $names[(int) $d] ?? $d, (array) ($p->operating_weekdays ?? []));

        return 'Opera: '.implode(', ', $days).' · anticipación '.($p->cutoff_hours === null ? 'hereda' : "{$p->cutoff_hours} h");
    }

    /** @return list<string> */
    public static function period(string $from, string $to): array
    {
        $out = [];
        for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $to; $d = $d->addDay()) {
            $out[] = $d->toDateString();
        }

        return $out;
    }

    /** CAT-AV-02/05: normaliza y valida el cuerpo. */
    private function validated(array $data, ?AvailabilityRange $current = null): array
    {
        $status = (string) ($data['status'] ?? '');
        if (! in_array($status, AvailabilityRange::STATUSES, true)) {
            throw ApiException::invalid('status', 'El estado debe ser disponible, cerrado o agotado.');
        }
        $from = $this->date((string) (($data['date_from'] ?? null) instanceof \DateTimeInterface ? $data['date_from']->format('Y-m-d') : ($data['date_from'] ?? '')), 'date_from');
        $to = $this->date((string) (($data['date_to'] ?? null) instanceof \DateTimeInterface ? $data['date_to']->format('Y-m-d') : ($data['date_to'] ?? '')), 'date_to');
        if ($to < $from) {
            throw ApiException::invalid('date_to', 'La fecha final debe ser igual o posterior a la inicial.');
        }
        if ($to < $this->clock->today()->toDateString()) {
            throw ApiException::unprocessable('CAT-AV-05', 'El rango no puede terminar en el pasado.');
        }
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > self::MAX_SPAN_DAYS) {
            throw ApiException::unprocessable('CAT-AV-05', 'Un rango no puede durar más de 730 días.');
        }
        $cap = $data['capacity_per_day'] ?? null;
        if ($status === 'disponible') {
            if (! is_numeric($cap) || (int) $cap != $cap || $cap < 1 || $cap > self::MAX_CAPACITY) {
                throw ApiException::unprocessable('CAT-AV-02', 'La capacidad por día debe ser un entero entre 1 y 500.');
            }
            $cap = (int) $cap;
        } else {
            $cap = 0;
        }
        $note = isset($data['note']) && trim((string) $data['note']) !== '' ? mb_substr(trim((string) $data['note']), 0, 200) : null;

        return ['date_from' => $from, 'date_to' => $to, 'status' => $status, 'capacity_per_day' => $cap, 'note' => $note];
    }

    private function date(string $value, string $field): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
        if (! $d || $d->format('Y-m-d') !== substr($value, 0, 10)) {
            throw ApiException::invalid($field, 'Fecha inválida (AAAA-MM-DD).');
        }

        return $d->format('Y-m-d');
    }

    /** CAT-AV-01: dos rangos del mismo tipo no se superponen; un cierre sí puede ir sobre un disponible. */
    private function assertNoOverlap(Product $product, array $row, ?string $exceptId = null): void
    {
        $open = $row['status'] === 'disponible';
        $conflicts = AvailabilityRange::where('product_id', $product->id)->where('date_from', '<=', $row['date_to'])->where('date_to', '>=', $row['date_from'])
            ->when($open, fn ($q) => $q->where('status', 'disponible'), fn ($q) => $q->whereIn('status', ['cerrado', 'agotado']))
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->orderBy('date_from')->get();
        if ($conflicts->isNotEmpty()) {
            throw ApiException::unprocessable('CAT-AV-01', 'CAT-AV-01: el rango se superpone con '.$conflicts->pluck('code')->implode(', ').($open
                ? '. Dos rangos disponibles no pueden solaparse.' : '. Ya hay un cierre en esas fechas.'), [
                    'conflicts' => $conflicts->map(fn (AvailabilityRange $r) => ['code' => $r->code, 'date_from' => $r->date_from->toDateString(), 'date_to' => $r->date_to->toDateString(), 'status' => $r->status])->all(),
                ]);
        }
    }

    /** CAT-AV-03 (capacidad bajo lo vendido) y CAT-AV-04 (cierre con ventas: confirmación explícita y aviso a Operaciones). */
    private function assertSales(Product $product, array $row, bool $confirm): void
    {
        $sales = $this->salesIn($product, $row['date_from'], $row['date_to']);
        if (isset($row['dates'])) {
            $sales = array_values(array_filter($sales, fn ($s) => in_array($s['date'], $row['dates'], true)));
        }
        if ($sales === []) {
            return;
        }
        if ($row['status'] === 'disponible') {
            $max = max(array_column($sales, 'pax'));
            if ($row['capacity_per_day'] < $max) {
                throw ApiException::unprocessable('CAT-AV-03', "CAT-AV-03: la capacidad no puede ser menor que lo vendido ({$max} pax en una de sus fechas).", ['affected' => $sales]);
            }

            return;
        }
        if (! $confirm) {
            throw ApiException::conflict('CAT-AV-04', 'Hay ventas en fechas que se cerrarían. Confirme para cerrar igual: las reservas no se anulan y se avisa a Operaciones.', ['affected' => $sales]);
        }
        AvailabilityClosedWithSales::dispatch($product->id, $product->ref, $product->name, $sales);
    }

    /** Transacción + traducción de la exclusion constraint (23P01) + invalidación de caché. */
    private function persist(Product $product, callable $work): mixed
    {
        try {
            $result = DB::transaction(function () use ($product, $work) {
                DB::table('catalog_products')->where('id', $product->id)->lockForUpdate()->first();

                return $work();
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23P01') {
                throw ApiException::unprocessable('CAT-AV-01', 'CAT-AV-01: el rango se superpone con otro del mismo tipo.');
            }
            throw $e;
        }
        AvailabilityRangeChanged::dispatch($product->id);

        return $result;
    }
}
