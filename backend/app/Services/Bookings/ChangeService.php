<?php

namespace App\Services\Bookings;

use App\Events\Bookings\ReservationModified;
use App\Events\Bookings\ReservationRescheduled;
use App\Exceptions\ApiException;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\BookingChange;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Departure;
use App\Models\Catalog\Product;
use App\Models\Catalog\Rate;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Catalog\AvailabilityService;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Catalog\SeasonService;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use App\Services\Quotes\QuotePricing;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Modificación (PROC-027, §4.4) y reprogramación (PROC-029, §4.5). */
class ChangeService
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly BookingInventory $inventory,
        private readonly AvailabilityService $availability,
        private readonly ReceivableService $receivables,
        private readonly QuotePricing $pricing,
        private readonly SeasonService $seasons,
        private readonly CrmClock $clock,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    // ── Modificación (§4.4) ────────────────────────────────────────────

    /** Impacto sugerido (editable) y disponibilidad según el tipo. */
    public function preview(Reservation $reservation, array $data): array
    {
        $this->reservations->assertOpen($reservation);
        $type = $data['type'] ?? '';
        if (! in_array($type, ['pax', 'servicio', 'upgrade', 'otro'], true)) {
            throw ApiException::invalid('type', 'Elija el tipo de modificación: pax, servicio, upgrade u otro.');
        }
        $pax = $this->paxCount($reservation);
        $result = ['type' => $type, 'suggested_delta' => '0.00', 'availability' => ['ok' => true, 'message' => 'Sin impacto en cupos.'], 'currency' => $reservation->currency];
        if ($type === 'pax') {
            $n = (int) ($data['extra_pax'] ?? 0);
            if ($n < 1) {
                throw ApiException::invalid('extra_pax', 'Indique cuántos pasajeros agrega (≥ 1).');
            }
            $result['suggested_delta'] = (string) BigDecimal::of((string) $reservation->total_amount)->multipliedBy($n)->dividedBy(max(1, $pax), 2, RoundingMode::HalfUp);
            $result['availability'] = $this->paxAvailability($reservation, $n);
        } elseif ($type === 'servicio') {
            [$product, $date] = $this->serviceInputs($data);
            $quote = $this->servicePrice($reservation, $product, $date, $pax);
            $result['suggested_delta'] = $quote['price'];
            $result['suggested_cost'] = $quote['cost'];
            $result['rate'] = $quote['rate'];
            $result['availability'] = $this->inventory->check($product, $date, $pax);
            $result['pax'] = $pax;
        }

        return $result;
    }

    /** RES-BR-14: registro `pendiente`; con `quotes.approve_internal` y `apply_now` se aplica de inmediato. */
    public function request(Reservation $reservation, array $data, User $actor): BookingChange
    {
        $preview = $this->preview($reservation, $data);
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            throw ApiException::invalid('description', 'Describa la modificación (RES-BR-14).');
        }
        if (! $preview['availability']['ok']) {
            throw ApiException::rule($preview['availability']['rule'] ?? 'PRD-BR-012', $preview['availability']['message'], ['alternatives' => $preview['availability']['alternatives'] ?? []]);
        }
        $delta = isset($data['delta_amount']) && $data['delta_amount'] !== '' ? (string) BigDecimal::of((string) $data['delta_amount'])->toScale(2, RoundingMode::HalfUp) : $preview['suggested_delta'];
        $change = BookingChange::create([
            'reservation_id' => $reservation->id, 'type' => $preview['type'], 'description' => $description,
            'extra_pax' => $preview['type'] === 'pax' ? (int) $data['extra_pax'] : null,
            'product_id' => $preview['type'] === 'servicio' ? $data['product_id'] : null,
            'service_date' => $preview['type'] === 'servicio' ? $data['service_date'] : null,
            'delta_amount' => $delta, 'status' => 'pendiente', 'requested_by' => $actor->id, 'requested_at' => now(),
            'payload' => ['suggested_delta' => $preview['suggested_delta'], 'suggested_cost' => $preview['suggested_cost'] ?? null, 'pax' => $preview['pax'] ?? null,
                'total_before' => (string) $reservation->total_amount],
        ]);
        $this->audit->log('Reserva', $reservation->code, 'solicitar_modificacion', null,
            BookingChange::TYPES[$change->type]." · {$reservation->currency} {$delta} · {$description}", actor: $actor);

        if (! empty($data['apply_now']) && $actor->hasPermissionTo(BookingAccess::APPROVE_CHANGES)) {
            return $this->approve($reservation, $change, $actor);
        }
        foreach (CatalogRecipients::role(['manager', 'tenant_admin'])->filter(fn (User $u) => $u->hasPermissionTo(BookingAccess::APPROVE_CHANGES)) as $approver) {
            $this->notify->push('reserva.modificacion', 'push', $approver, "Modificación pendiente de aprobación · {$reservation->code}",
                BookingChange::TYPES[$change->type]." · {$reservation->currency} {$delta}", ['related_type' => 'reservation', 'related_id' => $reservation->id]);
        }

        return $change->fresh();
    }

    public function approve(Reservation $reservation, BookingChange $change, User $actor): BookingChange
    {
        $this->assertPending($change);
        $this->reservations->assertOpen($reservation);
        $product = $change->product_id ? Product::with(['items.service', 'itinerary.days.items', 'policies'])->find($change->product_id) : null;
        $date = $change->service_date?->toDateString();
        $ids = match ($change->type) {
            'pax' => $this->heldDepartures($reservation),
            'servicio' => BookingInventory::bySalida($product) ? array_filter([$this->inventory->departureFor($product, $date)?->id]) : [],
            default => [],
        };

        return $this->availability->lockMany($ids, function (Collection $departures) use ($reservation, $change, $product, $date, $actor) {
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $change = BookingChange::whereKey($change->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($change);
            $delta = BigDecimal::of((string) $change->delta_amount);
            $pax = $this->paxCount($reservation);

            if ($change->type === 'pax') {
                $n = (int) $change->extra_pax;
                foreach ($departures as $d) {
                    if ($this->availability->available($d) < $n) {
                        throw ApiException::rule('PRD-BR-012', "Sin disponibilidad: la salida {$d->ref} no tiene {$n} cupo(s) libre(s) (PRD-BR-012).");
                    }
                    $this->availability->addToBooking($d, $reservation->id, $n, $actor);
                }
                $this->reservations->placeholders($reservation, $n, 0, 0, 'ADT');
                $reservation->services()->whereNotIn('status', BookedService::INACTIVE)->whereNotNull('pax')->increment('pax', $n);
            } elseif ($change->type === 'servicio') {
                $departure = $departures->first();
                $check = $this->inventory->check($product, $date, $pax, $departure);
                if (! $check['ok']) {
                    throw ApiException::rule($check['rule'] ?? 'PRD-BR-012', $check['message'], ['alternatives' => $check['alternatives']]);
                }
                if ($departure) {
                    $this->availability->bookLocked($departure, $pax, $reservation->code, $reservation->id, $actor);
                }
                $cost = $change->payload['suggested_cost'] ?? $this->servicePrice($reservation, $product, $date, $pax)['cost'];
                $price = $delta->isNegative() ? '0.00' : (string) $delta;
                $rows = ServiceBreakdown::splitTotals($product, $pax, (string) $cost, $price, $reservation->currency, (string) $reservation->fx_rate);
                $this->reservations->persistServices($reservation, $rows, $product, $date, $pax, $departure?->id, null);
                $reservation->forceFill([
                    'travel_from' => min($reservation->travel_from->toDateString(), $date),
                    'travel_to' => max($reservation->travel_to->toDateString(), $date),
                ]);
            }

            $before = (string) $reservation->total_amount;
            $reservation->forceFill(['total_amount' => (string) BigDecimal::of($before)->plus($delta)->toScale(2)])->save();
            $credit = null;
            if ($delta->isPositive()) {
                $due = max($this->clock->today()->toDateString(), $reservation->travel_from->copy()->subDays(7)->toDateString());
                $this->receivables->create($reservation, "Ajuste por modificación — {$change->description}", (string) $delta, $due, $actor, 'modificación');
            } elseif ($delta->isNegative()) {
                $credit = $this->receivables->reduce($reservation, (string) $delta->abs(), $actor);
            }
            $change->forceFill([
                'status' => 'aplicada', 'decided_by' => $actor->id, 'decided_at' => now(), 'applied_at' => now(),
                'credit_amount' => $credit !== null && BigDecimal::of($credit)->isPositive() ? $credit : null,
                'payload' => ($change->payload ?? []) + ['total_after' => (string) $reservation->total_amount],
            ])->save();
            $this->audit->log('Reserva', $reservation->code, 'modificar', "{$reservation->currency} {$before}",
                BookingChange::TYPES[$change->type]." · {$reservation->currency} {$reservation->total_amount} (Δ {$change->delta_amount})".($change->credit_amount ? " · saldo a favor {$change->credit_amount}" : ''), actor: $actor);
            if ($email = $reservation->client?->email) {
                $this->notify->push('reserva.modificada', 'email', $email, "Su reserva {$reservation->code} fue modificada", $change->description,
                    ['client_id' => $reservation->client_id, 'related_type' => 'reservation', 'related_id' => $reservation->id]);
            }
            ReservationModified::dispatch($reservation->id);

            return $change->fresh();
        });
    }

    public function reject(Reservation $reservation, BookingChange $change, ?string $note, User $actor): BookingChange
    {
        $this->assertPending($change);
        $change->forceFill(['status' => 'rechazada', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note])->save();
        $this->audit->log('Reserva', $reservation->code, 'rechazar_modificacion', BookingChange::TYPES[$change->type], 'Rechazada'.($note ? " · {$note}" : ''), actor: $actor);

        return $change->fresh();
    }

    private function assertPending(BookingChange $change): void
    {
        if ($change->status !== 'pendiente') {
            throw ApiException::rule('RES-BR-14', 'La modificación ya fue '.($change->status === 'aplicada' ? 'aplicada' : 'rechazada'));
        }
    }

    /** Pax de la reserva para precios: pasajeros registrados sin infantes (o el mayor pax de servicio). */
    private function paxCount(Reservation $reservation): int
    {
        $count = $reservation->passengers()->where('pax_type', '<>', 'INF')->count();

        return max(1, $count ?: (int) $reservation->services()->max('pax'));
    }

    /** @return list<string> */
    private function heldDepartures(Reservation $reservation): array
    {
        return $reservation->services()->whereNotIn('status', BookedService::INACTIVE)->whereNotNull('departure_id')->pluck('departure_id')->unique()->values()->all();
    }

    private function paxAvailability(Reservation $reservation, int $n): array
    {
        foreach (Departure::whereIn('id', $this->heldDepartures($reservation))->orderBy('departure_date')->get() as $d) {
            if ($this->availability->available($d) < $n) {
                return ['ok' => false, 'rule' => 'PRD-BR-012', 'message' => "Sin disponibilidad: la salida {$d->ref} no tiene {$n} cupo(s) libre(s) (PRD-BR-012).",
                    'alternatives' => $this->availability->alternatives($d, $n)];
            }
        }

        return ['ok' => true, 'rule' => null, 'message' => 'Hay cupo en las salidas retenidas.'];
    }

    /** @return array{0: Product, 1: string} */
    private function serviceInputs(array $data): array
    {
        $product = Product::with(['items.service', 'itinerary.days.items', 'policies'])->find($data['product_id'] ?? null)
            ?? throw ApiException::invalid('product_id', 'Seleccione el producto del servicio adicional.');
        if ($product->status !== Product::ACTIVE || ! $product->is_bookable) {
            throw ApiException::unprocessable('PRD-BR-002', "PRD-BR-002: {$product->ref} no está activo o no es reservable.");
        }
        $date = (string) ($data['service_date'] ?? '');
        if ($date === '' || $date < $this->clock->today()->toDateString()) {
            throw ApiException::invalid('service_date', 'Indique una fecha de servicio de hoy en adelante.');
        }

        return [$product, $date];
    }

    /** Precio sugerido de la tarifa por defecto × pasajeros, en la moneda de la reserva (sin IGV). */
    private function servicePrice(Reservation $reservation, Product $product, string $date, int $pax): array
    {
        $rate = $this->pricing->defaultRate(Rate::where('product_id', $product->id)->get(), $date, $reservation->client);
        if ($rate === null) {
            return ['price' => '0.00', 'cost' => '0.00', 'rate' => null];
        }
        $s = $this->pricing->suggest($rate, $date, $reservation->currency, (string) $reservation->fx_rate);

        return [
            'price' => (string) BigDecimal::of($s['unit_price'])->multipliedBy($pax)->toScale(2),
            'cost' => (string) BigDecimal::of($s['unit_cost'])->multipliedBy($pax)->toScale(2),
            'rate' => $rate->ref,
        ];
    }

    // ── Reprogramación (§4.5) ──────────────────────────────────────────

    /** Fecha propuesta: próxima salida con cupo del primer servicio con salida; si no, inicio + 7. */
    public function proposedStart(Reservation $reservation): string
    {
        $first = $reservation->services()->with('product')->whereNotIn('status', BookedService::INACTIVE)->whereNotNull('departure_id')->first();
        if ($first) {
            $current = Departure::find($first->departure_id);
            $next = $current ? collect($this->availability->alternatives($current, (int) $first->pax))
                ->first(fn (array $d) => $d['date'] > $current->departure_date->toDateString()) : null;
            if ($next) {
                return Carbon::parse($reservation->travel_from)->addDays((int) $first->service_date->diffInDays(Carbon::parse($next['date'])))->toDateString();
            }
        }

        return $reservation->travel_from->copy()->addDays(7)->toDateString();
    }

    /** Tabla por servicio: nueva fecha, disponibilidad, diferencia por temporada (RES-BR-20…22). */
    public function reschedulePreview(Reservation $reservation, ?string $newStart): array
    {
        $this->reservations->assertOpen($reservation);
        $newStart = $newStart ?: $this->proposedStart($reservation);
        $services = $reservation->services()->with('product')->get();
        $completed = $services->first(fn (BookedService $s) => $s->status === BookedService::COMPLETED);
        $shift = (int) $reservation->travel_from->diffInDays(Carbon::parse($newStart), false);
        $blockers = [];
        if ($completed) {
            $blockers[] = "RES-BR-20: el servicio {$completed->code} ya se ejecutó; use Modificar.";
        }
        if ($shift === 0) {
            $blockers[] = 'RES-BR-21: la nueva fecha debe ser distinta de la actual.';
        }
        if ($newStart < $this->clock->today()->toDateString()) {
            $blockers[] = 'RES-BR-21: la nueva fecha no puede ser pasada.';
        }

        $lines = [];
        $diff = BigDecimal::zero();
        foreach ($services->filter(fn (BookedService $s) => $s->isActive()) as $s) {
            $old = $s->service_date->toDateString();
            $new = $s->service_date->copy()->addDays($shift)->toDateString();
            $fo = $this->factor($old);
            $fn = $this->factor($new);
            $dp = BigDecimal::of((string) $s->price_amount)->multipliedBy($fn)->dividedBy($fo, 2, RoundingMode::HalfUp)->minus((string) $s->price_amount);
            $diff = $diff->plus($dp);
            $check = ['ok' => true, 'message' => 'Hold con proveedor.', 'departure' => null];
            if ($s->product && $s->product->status !== Product::ACTIVE) {
                $check = ['ok' => false, 'message' => "PRD-BR-002: {$s->product->ref} no está ACTIVO.", 'departure' => null];
            } elseif (BookingInventory::bySalida($s->product)) {
                $target = $this->inventory->departureFor($s->product, $new);
                $check = $this->inventory->check($s->product, $new, (int) $s->pax, $target, $target && $target->id === $s->departure_id ? (int) $s->pax : 0);
            }
            if (! $check['ok']) {
                $blockers[] = "RT-4: {$s->code} {$s->name} el {$new}: {$check['message']}";
            }
            $lines[] = [
                'code' => $s->code, 'name' => $s->name, 'old_date' => $old, 'new_date' => $new, 'pax' => $s->pax,
                'factor_old' => $fo, 'factor_new' => $fn, 'price' => (string) $s->price_amount, 'diff' => (string) $dp,
                'ok' => $check['ok'], 'message' => $check['message'], 'departure' => $check['departure'] ?? null,
            ];
        }

        return [
            'new_start' => $newStart, 'new_end' => $reservation->travel_to->copy()->addDays($shift)->toDateString(), 'shift_days' => $shift,
            'lines' => $lines, 'diff' => (string) $diff->toScale(2), 'currency' => $reservation->currency,
            'blockers' => $blockers, 'can_apply' => $blockers === [],
        ];
    }

    public function reschedule(Reservation $reservation, string $newStart, string $reason, User $actor): Reservation
    {
        if (trim($reason) === '') {
            throw ApiException::invalid('reason', 'Indique el motivo de la reprogramación (RES-BR-21).');
        }
        $preview = $this->reschedulePreview($reservation, $newStart);
        if (! $preview['can_apply']) {
            throw ApiException::rule(str_starts_with($preview['blockers'][0], 'RT-4') ? 'RT-4' : substr($preview['blockers'][0], 0, 9), $preview['blockers'][0], ['blockers' => $preview['blockers']]);
        }
        $shift = $preview['shift_days'];
        $services = $reservation->services()->with('product')->get()->filter(fn (BookedService $s) => $s->isActive());
        $oldIds = $services->pluck('departure_id')->filter()->unique()->values()->all();
        $targets = [];
        foreach ($services as $s) {
            if (BookingInventory::bySalida($s->product)) {
                $targets[$s->id] = $this->inventory->departureFor($s->product, $s->service_date->copy()->addDays($shift)->toDateString())?->id;
            }
        }

        return $this->availability->lockMany([...$oldIds, ...array_filter($targets)], function (Collection $departures) use ($reservation, $services, $targets, $shift, $reason, $preview, $actor) {
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $this->reservations->assertOpen($reservation);
            foreach ($services->pluck('departure_id')->filter()->unique() as $id) {
                $this->availability->releaseLocked($departures[$id], $reservation->id, "Reprogramación {$reservation->code}", $actor);
            }
            // Una retención por salida nueva con el mayor pax de sus servicios.
            $need = [];
            foreach ($targets as $sid => $did) {
                if ($did) {
                    $need[$did] = max($need[$did] ?? 0, (int) $services->firstWhere('id', $sid)->pax);
                }
            }
            foreach ($need as $did => $pax) {
                $d = $departures[$did];
                if ($this->availability->available($d) < $pax) {
                    throw ApiException::rule('RT-4', "RT-4: la salida {$d->ref} no tiene {$pax} cupo(s) libre(s) (PRD-BR-012).");
                }
                $this->availability->bookLocked($d, $pax, $reservation->code, $reservation->id, $actor);
            }
            foreach ($services as $s) {
                $old = $s->service_date->toDateString();
                $new = $s->service_date->copy()->addDays($shift)->toDateString();
                $ratio = BigDecimal::of($this->factor($new))->dividedBy($this->factor($old), 10, RoundingMode::HalfUp);
                $did = $targets[$s->id] ?? null;
                $name = $did ? preg_replace('/SAL-\d+/', $departures[$did]->ref, $s->name) : $s->name;
                $s->forceFill([
                    'service_date' => $new, 'departure_id' => $did, 'name' => $name, 'status' => BookedService::PENDING,
                    'confirmation_code' => null, 'confirmed_by_name' => null, 'confirmed_at' => null, 'requested_at' => null,
                    'price_amount' => (string) BigDecimal::of((string) $s->price_amount)->multipliedBy($ratio)->toScale(2, RoundingMode::HalfUp),
                    'cost_amount' => (string) BigDecimal::of((string) $s->cost_amount)->multipliedBy($ratio)->toScale(2, RoundingMode::HalfUp),
                ])->save();
            }

            $diff = BigDecimal::of($preview['diff']);
            $before = "{$reservation->travel_from->toDateString()} → {$reservation->travel_to->toDateString()} · {$reservation->currency} {$reservation->total_amount}";
            $reservation->forceFill([
                'travel_from' => $preview['new_start'], 'travel_to' => $preview['new_end'], 'status' => Reservation::RESCHEDULED, 'rescheduled_at' => now(),
                'total_amount' => (string) BigDecimal::of((string) $reservation->total_amount)->plus($diff)->toScale(2),
            ])->save();
            BookingChange::create([
                'reservation_id' => $reservation->id, 'type' => 'reprogramacion', 'description' => $reason, 'delta_amount' => (string) $diff->toScale(2),
                'status' => 'aplicada', 'requested_by' => $actor->id, 'requested_at' => now(), 'decided_by' => $actor->id, 'decided_at' => now(), 'applied_at' => now(),
                'payload' => ['shift_days' => $shift, 'from' => $before, 'to' => "{$preview['new_start']} → {$preview['new_end']}", 'lines' => $preview['lines']],
            ]);
            if ($diff->isPositive()) {
                $due = max($this->clock->today()->toDateString(), Carbon::parse($preview['new_start'])->subDays(7)->toDateString());
                $this->receivables->create($reservation, "Diferencia tarifaria por reprogramación — {$reservation->title}", (string) $diff->toScale(2), $due, $actor, 'reprogramación');
            }
            $this->audit->log('Reserva', $reservation->code, 'reprogramar', $before,
                "{$preview['new_start']} → {$preview['new_end']} · {$reservation->currency} {$reservation->total_amount} (dif. {$preview['diff']}) · {$reason}", actor: $actor);

            $data = ['client_id' => $reservation->client_id, 'related_type' => 'reservation', 'related_id' => $reservation->id];
            if ($email = $reservation->client?->email) {
                $this->notify->push('cambio.horario', 'email', $email, "Su reserva {$reservation->code} fue reprogramada", "Nuevas fechas: {$preview['new_start']} al {$preview['new_end']}", $data);
            }
            if ($coordinator = CatalogRecipients::operations()->first()) {
                $this->notify->push('cambio.horario', 'push', $coordinator, "Reprogramación {$reservation->code}", "{$preview['new_start']} al {$preview['new_end']} · servicios por solicitar de nuevo", $data);
            }
            ReservationRescheduled::dispatch($reservation->id);

            return $reservation->fresh();
        });
    }

    /** Factor de temporada de la fecha (1 si no hay temporada activa). */
    private function factor(string $date): string
    {
        return (string) ($this->seasons->resolve($date)?->factor ?? '1');
    }
}
