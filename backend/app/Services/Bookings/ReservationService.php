<?php

namespace App\Services\Bookings;

use App\Events\Bookings\ReservationConfirmed;
use App\Events\Bookings\ReservationCreated;
use App\Events\Bookings\ReservationReassigned;
use App\Events\Quotes\QuoteConverted;
use App\Exceptions\ApiException;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\Passenger;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Departure;
use App\Models\Catalog\Product;
use App\Models\Catalog\Rate;
use App\Models\Crm\Client;
use App\Models\Iam\User;
use App\Models\Quotes\Quote;
use App\Models\Quotes\QuoteItem;
use App\Models\Quotes\QuoteVersion;
use App\Services\AuditLogger;
use App\Services\Catalog\AvailabilityService;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use App\Services\Quotes\QuotePricing;
use App\Services\SequenceService;
use App\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Nacimiento y ciclo base de la reserva: conversión idempotente desde cotización (PROC-025, §4.3),
 * reserva directa (§4.7), confirmación, operación, pasajeros y reasignación (PROC-026).
 */
class ReservationService
{
    public function __construct(
        private readonly BookingAccess $access,
        private readonly BookingInventory $inventory,
        private readonly AvailabilityService $availability,
        private readonly ReceivableService $receivables,
        private readonly QuotePricing $pricing,
        private readonly CrmAccess $crm,
        private readonly CrmClock $clock,
        private readonly SequenceService $sequences,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    // ── Conversión cotización → reserva (§4.3) ─────────────────────────

    /** Modal "Convertir en reserva": precondiciones (RT-3) y disponibilidad por ítem con alternativas. */
    public function conversionPreview(Quote $quote): array
    {
        $existing = $this->existingFor($quote);
        $version = $quote->currentVersion()->with('items.product')->first();
        $checks = $this->preconditions($quote, $version);
        $items = $version ? $version->items->map(fn (QuoteItem $i) => [
            'line_no' => $i->line_no, 'product' => $i->product ? "{$i->product->ref} · {$i->product->name}" : null, 'service_date' => $i->service_date->toDateString(),
            'pax' => $i->pax, ...$this->inventory->check($i->product, $i->service_date->toDateString(), $i->pax),
        ])->all() : [];
        $blocked = collect($items)->where('ok', false)->count();

        return [
            'existing' => $existing ? ['code' => $existing->code, 'status' => $existing->status] : null,
            'checks' => $checks,
            'items' => $items,
            'can_convert' => $existing === null && collect($checks)->every('ok') && $blocked === 0,
            'message' => match (true) {
                $existing !== null => "La cotización ya fue convertida en la reserva {$existing->code}. No se creará una reserva duplicada.",
                $blocked > 0 => 'Hay ítems sin cupo: no hay conversión parcial (RES-BR-01). Cree una nueva versión con fecha alternativa (requiere nueva aprobación).',
                ! collect($checks)->every('ok') => 'La cotización no cumple las precondiciones para generar la reserva (RT-3).',
                default => 'Todo listo: la reserva nace confirmada con servicios pendientes de solicitud a proveedor.',
            },
        ];
    }

    /**
     * PROC-025 (§4.3 pasos 1–19). Devuelve [reserva, creada]; si ya existía, `creada` = false (200).
     *
     * @return array{0: Reservation, 1: bool}
     */
    public function convert(Quote $quote, int $ifMatch, User $actor): array
    {
        $lock = Cache::lock("tenant:{$this->tenant->id()}:quote:{$quote->id}:convert", 30);
        if (! $lock->block(5)) {
            throw ApiException::conflict('CONVERSION_IN_PROGRESS', 'Conversión en curso: espere unos segundos y vuelva a intentar');
        }
        try {
            $version = $quote->currentVersion()->with('items.product')->first();
            $departureIds = $version ? $version->items->map(fn (QuoteItem $i) => BookingInventory::bySalida($i->product)
                ? $this->inventory->departureFor($i->product, $i->service_date->toDateString())?->id : null)->filter()->values()->all() : [];

            return $this->availability->lockMany($departureIds, fn (Collection $departures) => $this->doConvert($quote->id, $ifMatch, $departures, $actor));
        } finally {
            $lock->release();
        }
    }

    /** @return array{0: Reservation, 1: bool} */
    private function doConvert(string $quoteId, int $ifMatch, Collection $departures, User $actor): array
    {
        $quote = Quote::whereKey($quoteId)->lockForUpdate()->firstOrFail();
        if ((int) $quote->lock_version !== $ifMatch) {
            throw ApiException::conflict('STALE_VERSION', 'La cotización cambió desde que la abrió; recargue y vuelva a intentar');
        }
        if ($existing = $this->existingFor($quote)) {
            $this->audit->log('Cotización', $quote->code, 'convertir_idempotente', 'solicitud repetida', $existing->code, actor: $actor);

            return [$existing, false];
        }
        $version = $quote->currentVersion()->with(['items.product.items.service', 'items.product.itinerary.days.items', 'items.product.policies'])->firstOrFail();
        $failed = collect($this->preconditions($quote, $version))->firstWhere('ok', false);
        if ($failed) {
            throw ApiException::rule('RT-3', $failed['detail']);
        }

        // Paso 7: re-verificar dentro de la transacción (pax acumulados por salida).
        $needed = [];
        foreach ($version->items as $item) {
            $departure = BookingInventory::bySalida($item->product)
                ? $departures->first(fn (Departure $d) => $d->product_id === $item->product_id && $d->departure_date->toDateString() === $item->service_date->toDateString())
                : null;
            $pax = $item->pax + ($departure ? ($needed[$departure->id] ?? 0) : 0);
            $check = $this->inventory->check($item->product, $item->service_date->toDateString(), $pax, $departure);
            if (! $check['ok']) {
                throw ApiException::rule($check['rule'] ?? 'RT-4', "RT-4 / {$check['message']} No hay conversión parcial: cree una nueva versión con fecha alternativa.",
                    ['line_no' => $item->line_no, 'alternatives' => $check['alternatives']]);
            }
            if ($departure) {
                $needed[$departure->id] = $pax;
            }
        }

        $client = Client::withTrashed()->findOrFail($quote->client_id);
        $owner = User::find($quote->owner_id);
        $policies = $version->policies_snapshot ?? [];
        foreach ($version->items as $item) {
            if (! isset($policies[$item->product_id]) && $item->product?->policies) {
                $policies[$item->product_id] = self::policyOf($item->product);
            }
        }
        $reservation = Reservation::create([
            'code' => $this->sequences->next('reservation'),
            'quote_id' => $quote->id, 'quote_version_id' => $version->id, 'quote_version_no' => $version->version_no,
            'client_id' => $client->id, 'owner_id' => $quote->owner_id, 'sede_id' => $owner?->sede_id,
            'title' => $quote->title, 'status' => Reservation::CONFIRMED, 'channel' => self::channelFor($client),
            'travel_from' => $quote->travel_from->toDateString(), 'travel_to' => $quote->travel_to->toDateString(),
            'currency' => $quote->currency, 'fx_rate' => (string) $version->fx_rate,
            'total_amount' => (string) BigDecimal::of((string) $version->grand_total)->toScale(2, RoundingMode::HalfUp),
            'snapshot_label' => $version->snapshot_label, 'catalog_snapshot' => $version->catalog_snapshot, 'policies_snapshot' => $policies,
            'notes' => trim("Generada desde {$quote->code} v{$version->version_no}. ".($version->notes ?? '')),
        ]);

        foreach ($version->items as $item) {
            $departure = BookingInventory::bySalida($item->product)
                ? $departures->first(fn (Departure $d) => $d->product_id === $item->product_id && $d->departure_date->toDateString() === $item->service_date->toDateString())
                : null;
            if ($departure) {
                $this->availability->bookLocked($departure, $item->pax, $reservation->code, $reservation->id, $actor);
            }
            $rows = ServiceBreakdown::split($item->product, $item->pax, $item->qty, (string) $item->unit_cost, (string) $item->unit_price,
                (string) $version->discount_pct, $quote->currency, (string) $version->fx_rate);
            $this->persistServices($reservation, $rows, $item->product, $item->service_date->toDateString(), $item->pax, $departure?->id, $item->id);
        }
        $this->placeholders($reservation, $quote->pax_adt, $quote->pax_chd, $quote->pax_inf);
        $this->receivables->schedule($reservation, $actor, "{$quote->code} v{$version->version_no}");

        $quote->forceFill(['status' => Quote::CONVERTED, 'reservation_id' => $reservation->id, 'converted_at' => now()])->save();
        QuoteConverted::dispatch($quote->id, $quote->opportunity_id);

        $this->audit->log('Reserva', $reservation->code, 'crear_desde_cotizacion', "{$quote->code} v{$version->version_no}",
            "confirmada · {$reservation->currency} {$reservation->total_amount}", actor: $actor);
        $this->audit->log('Cotización', $quote->code, 'convertir', 'Aprobada', "Convertida → {$reservation->code}", actor: $actor);
        ReservationCreated::dispatch($reservation->id);
        ReservationConfirmed::dispatch($reservation->id);
        $this->notifyConfirmed($reservation, $owner);

        return [$reservation->fresh(), true];
    }

    /** RT-22: la reserva de la cotización (aunque esté cancelada) si ya se convirtió. */
    private function existingFor(Quote $quote): ?Reservation
    {
        if ($quote->reservation_id) {
            return Reservation::withTrashed()->find($quote->reservation_id);
        }

        return Reservation::where('quote_id', $quote->id)->first();
    }

    /** @return list<array{key: string, label: string, ok: bool, detail: string}> */
    private function preconditions(Quote $quote, ?QuoteVersion $version): array
    {
        $label = Quote::STATUS_LABELS[$quote->status] ?? $quote->status;
        $paxTotal = $quote->pax_adt + $quote->pax_chd + $quote->pax_inf;

        return [
            ['key' => 'status', 'label' => 'Cotización aprobada por el cliente', 'ok' => $quote->status === Quote::APPROVED,
                'detail' => $quote->status === Quote::APPROVED ? 'Aprobada' : "RT-3: solo una cotización aprobada puede generar reserva (estado actual: {$label})"],
            ['key' => 'version', 'label' => 'Versión vigente aprobada', 'ok' => $version?->status === QuoteVersion::APPROVED,
                'detail' => $version ? "Versión {$version->version_no}: {$version->status}" : 'Sin versión vigente'],
            ['key' => 'items', 'label' => 'Al menos un ítem', 'ok' => (bool) $version?->items->count(), 'detail' => ($version?->items->count() ?? 0).' ítem(s)'],
            ['key' => 'pax', 'label' => 'Pasajeros', 'ok' => $paxTotal > 0, 'detail' => "{$quote->pax_adt} ADT · {$quote->pax_chd} CHD · {$quote->pax_inf} INF"],
            ['key' => 'total', 'label' => 'Total mayor a cero', 'ok' => $version !== null && (float) $version->grand_total > 0, 'detail' => "{$quote->currency} ".($version?->grand_total ?? '0.00')],
            ['key' => 'client', 'label' => 'Cliente existente', 'ok' => Client::whereKey($quote->client_id)->exists(), 'detail' => (string) $quote->client?->name],
        ];
    }

    // ── Reserva directa (§4.7) ─────────────────────────────────────────

    /** Panel de disponibilidad y precio de la reserva directa (RES-BR-04/05/06). */
    public function directPreview(array $data, User $actor): array
    {
        [$product, $rate, $departure, $date, $warnings] = $this->directInputs($data, $actor);
        $adt = (int) ($data['adt'] ?? 0);
        $chd = (int) ($data['chd'] ?? 0);
        $price = $this->directPrice($product, $rate, $date, $adt, $chd);
        $check = $this->inventory->check($product, $date, $adt + $chd, $departure);

        return $price + ['availability' => $check, 'warnings' => $warnings, 'travel_to' => $this->travelTo($product, $date), 'date' => $date];
    }

    public function createDirect(array $data, User $actor): Reservation
    {
        [$product, $rate, $departure, $date] = $this->directInputs($data, $actor);
        $client = Client::findOrFail($data['client_id']);
        $owner = $this->access->ownOnly($actor) ? $actor : User::findOrFail($data['owner_id'] ?? $actor->id);
        $adt = (int) $data['adt'];
        $chd = (int) ($data['chd'] ?? 0);
        $inf = (int) ($data['inf'] ?? 0);
        $confirm = (bool) ($data['confirm'] ?? false);
        $price = $this->directPrice($product, $rate, $date, $adt, $chd);

        return $this->availability->lockMany($departure ? [$departure->id] : [], function (Collection $locked) use ($data, $product, $departure, $date, $client, $owner, $adt, $chd, $inf, $confirm, $price, $actor) {
            $departure = $departure ? $locked[$departure->id] : null;
            $check = $this->inventory->check($product, $date, $adt + $chd, $departure);
            if ($confirm && ! $check['ok']) {
                throw ApiException::rule('RES-BR-05', "RES-BR-05: no se puede confirmar sin disponibilidad. {$check['message']} Guárdela como borrador.", ['alternatives' => $check['alternatives']]);
            }
            $status = match (true) {
                $confirm => Reservation::CONFIRMED,
                $check['ok'] => Reservation::DRAFT,
                default => Reservation::PENDING_AVAILABILITY,
            };
            $reservation = Reservation::create([
                'code' => $this->sequences->next('reservation'), 'client_id' => $client->id, 'owner_id' => $owner->id, 'sede_id' => $owner->sede_id,
                'title' => $data['title'] ?? "{$product->name} — {$client->name}", 'status' => $status,
                'channel' => in_array($data['channel'] ?? null, Reservation::CHANNELS, true) ? $data['channel'] : self::channelFor($client),
                'travel_from' => $date, 'travel_to' => $this->travelTo($product, $date), 'currency' => $price['currency'], 'fx_rate' => $this->pricing->fx(),
                'total_amount' => $price['total'], 'snapshot_label' => "{$product->ref} v".($product->currentVersion?->version ?? '1.0'),
                'policies_snapshot' => $product->policies ? [$product->id => self::policyOf($product)] : [],
                'notes' => $data['notes'] ?? null,
            ]);
            // §12-7: el borrador con disponibilidad también retiene cupo.
            if ($departure && $check['ok']) {
                $this->availability->bookLocked($departure, $adt + $chd, $reservation->code, $reservation->id, $actor);
            }
            $rows = ServiceBreakdown::splitTotals($product, $adt + $chd, $price['cost'], $price['total'], $price['currency'], $this->pricing->fx());
            $this->persistServices($reservation, $rows, $product, $date, $adt + $chd, $departure && $check['ok'] ? $departure->id : null, null);
            $this->placeholders($reservation, $adt, $chd, $inf);
            $this->receivables->schedule($reservation, $actor, 'reserva directa');
            $this->audit->log('Reserva', $reservation->code, 'crear', null,
                Reservation::STATUS_LABELS[$status]." · {$product->ref} · {$reservation->currency} {$reservation->total_amount}".($check['ok'] ? '' : " · {$check['message']}"), actor: $actor);
            ReservationCreated::dispatch($reservation->id);
            if ($status === Reservation::CONFIRMED) {
                ReservationConfirmed::dispatch($reservation->id);
                $this->notifyConfirmed($reservation, $owner);
            }

            return $reservation->fresh();
        });
    }

    /** @return array{0: Product, 1: Rate, 2: ?Departure, 3: string, 4: list<string>} */
    private function directInputs(array $data, User $actor): array
    {
        $client = Client::find($data['client_id'] ?? null) ?? throw ApiException::invalid('client_id', 'Seleccione el cliente (RES-BR-04).');
        if ($this->access->ownOnly($actor)) {
            $this->crm->ensureClient($client, $actor);
        }
        $product = Product::with(['policies', 'items.service', 'itinerary.days.items', 'currentVersion'])->find($data['product_id'] ?? null)
            ?? throw ApiException::invalid('product_id', 'Seleccione el producto (RES-BR-04).');
        if ($product->status !== Product::ACTIVE || ! $product->is_bookable) {
            throw ApiException::unprocessable('PRD-BR-002', "PRD-BR-002: {$product->ref} no está activo o no es reservable.");
        }
        $adt = (int) ($data['adt'] ?? 0);
        $chd = (int) ($data['chd'] ?? 0);
        if ($adt + $chd < 1) {
            throw ApiException::invalid('adt', 'Indique al menos un adulto o niño (RES-BR-04).');
        }
        $warnings = [];
        $departure = null;
        if (BookingInventory::bySalida($product)) {
            $departure = Departure::where('product_id', $product->id)->find($data['departure_id'] ?? null)
                ?? throw ApiException::invalid('departure_id', 'Seleccione la salida: el producto se vende por salida (RES-BR-06).');
            $date = $departure->departure_date->toDateString();
        } else {
            $date = (string) ($data['date'] ?? '') ?: throw ApiException::invalid('date', 'Indique la fecha del servicio.');
            $days = $this->clock->today()->diffInDays($date, false);
            if ($days < $product->booking_advance_min_days) {
                throw ApiException::invalid('date', "Requiere {$product->booking_advance_min_days} día(s) de anticipación mínima (RES-BR-06).");
            }
            if ($adt + $chd > $product->maximum_passengers) {
                throw ApiException::invalid('adt', "Máximo {$product->maximum_passengers} pasajeros por reserva (RES-BR-06).");
            }
        }
        if ($date < $this->clock->today()->toDateString()) {
            throw ApiException::invalid('date', 'La fecha no puede ser pasada.');
        }
        $rate = Rate::where('product_id', $product->id)->find($data['rate_id'] ?? null) ?? throw ApiException::invalid('rate_id', 'Seleccione la tarifa.');
        if (! QuotePricing::rateValidOn($rate, $date)) {
            throw ApiException::unprocessable('PRD-BR-005', "PRD-BR-005: la tarifa {$rate->ref} no está vigente el {$date}.");
        }
        if ($rate->segment === 'Niño') {
            throw ApiException::invalid('rate_id', 'Elija una tarifa de adulto: la de niño se aplica sola a los CHD.');
        }

        return [$product, $rate, $departure, $date, $warnings];
    }

    /** §4.7: adulto = tarifa sugerida; niño = tarifa Niño vigente del mismo canal (o adulto); infante sin cargo; sin IGV. */
    private function directPrice(Product $product, Rate $rate, string $date, int $adt, int $chd): array
    {
        $currency = $rate->currency;
        $sa = $this->pricing->suggest($rate, $date, $currency, $this->pricing->fx());
        $child = Rate::where('product_id', $product->id)->where('segment', 'Niño')->where('channel', $rate->channel)->get()
            ->first(fn (Rate $r) => QuotePricing::rateValidOn($r, $date));
        $sc = $child ? $this->pricing->suggest($child, $date, $currency, $this->pricing->fx()) : $sa;
        $total = BigDecimal::of($sa['unit_price'])->multipliedBy($adt)->plus(BigDecimal::of($sc['unit_price'])->multipliedBy($chd));
        $cost = BigDecimal::of($sa['unit_cost'])->multipliedBy($adt)->plus(BigDecimal::of($sc['unit_cost'])->multipliedBy($chd));

        return [
            'currency' => $currency, 'adult_price' => $sa['unit_price'], 'child_price' => $sc['unit_price'], 'child_rate' => $child?->ref,
            'total' => (string) $total->toScale(2), 'cost' => (string) $cost->toScale(2), 'season' => $sa['season'],
        ];
    }

    private function travelTo(Product $product, string $date): string
    {
        $days = $product->duration_unit === 'días' ? max(1, (int) ceil((float) $product->duration_value)) : 1;

        return Carbon::parse($date)->addDays($days - 1)->toDateString();
    }

    // ── Confirmación y operación (§4.1) ────────────────────────────────

    /** RES-BR-07: servicios activos requeridos con disponibilidad y ≥ 1 pasajero. */
    public function confirm(Reservation $reservation, User $actor): Reservation
    {
        if (! in_array($reservation->status, Reservation::CONFIRMABLE, true)) {
            throw ApiException::rule('RES-BR-07', 'Solo se confirma una reserva en borrador, pendiente de disponibilidad o de proveedor, o reprogramada');
        }
        $services = $reservation->services()->with('product')->get();
        if ($reservation->status === Reservation::PENDING_PROVIDER && $services->contains(fn (BookedService $s) => in_array($s->status, [BookedService::PENDING, BookedService::REQUESTED], true))) {
            $this->audit->log('Reserva', $reservation->code, 'verificar_disponibilidad', null, 'Servicios aún pendientes de confirmación del proveedor', actor: $actor);

            return $reservation;
        }
        if (! $reservation->passengers()->exists()) {
            throw ApiException::rule('RES-BR-07', 'Registre al menos un pasajero antes de confirmar');
        }
        $orphan = $services->first(fn (BookedService $s) => $s->status === BookedService::REJECTED && $s->replaced_by_id === null && $s->is_required);
        if ($orphan) {
            throw ApiException::rule('RES-BR-07', "El servicio {$orphan->code} ({$orphan->name}) fue rechazado por el proveedor y no tiene alternativa");
        }
        $toHold = $services->filter(fn (BookedService $s) => $s->isActive() && $s->is_required && $s->departure_id === null && BookingInventory::bySalida($s->product));
        $ids = $toHold->map(fn (BookedService $s) => $this->inventory->departureFor($s->product, $s->service_date->toDateString())?->id)->filter()->values()->all();

        return $this->availability->lockMany($ids, function (Collection $departures) use ($reservation, $services, $toHold, $actor) {
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $held = [];
            foreach ($services->filter(fn (BookedService $s) => $s->isActive() && $s->is_required) as $s) {
                $departure = $s->departure_id ? null : $departures->first(fn (Departure $d) => $d->product_id === $s->product_id && $d->departure_date->toDateString() === $s->service_date->toDateString());
                $check = $s->departure_id ? ['ok' => true] : $this->inventory->check($s->product, $s->service_date->toDateString(), (int) $s->pax, $departure);
                if (! $check['ok']) {
                    throw ApiException::rule('RT-4', "RT-4: {$s->code} {$s->name}: {$check['message']}", ['alternatives' => $check['alternatives'] ?? []]);
                }
            }
            foreach ($toHold as $s) {
                $departure = $departures->first(fn (Departure $d) => $d->product_id === $s->product_id && $d->departure_date->toDateString() === $s->service_date->toDateString());
                if ($departure && ! isset($held[$departure->id])) {
                    $this->availability->bookLocked($departure, (int) $s->pax, $reservation->code, $reservation->id, $actor);
                    $held[$departure->id] = true;
                }
                if ($departure) {
                    $s->forceFill(['departure_id' => $departure->id])->save();
                }
            }
            $before = $reservation->status;
            $reservation->forceFill(['status' => Reservation::CONFIRMED])->save();
            $this->receivables->schedule($reservation, $actor, 'confirmación');
            $this->audit->log('Reserva', $reservation->code, 'confirmar', Reservation::STATUS_LABELS[$before], 'Confirmada', actor: $actor);
            ReservationConfirmed::dispatch($reservation->id);
            $this->notifyConfirmed($reservation, $reservation->owner);

            return $reservation->fresh();
        });
    }

    /** RES-BR-08: iniciar operación desde el día previo al viaje. */
    public function startOperation(Reservation $reservation, User $actor): Reservation
    {
        if (! in_array($reservation->status, Reservation::STARTABLE, true)) {
            throw ApiException::rule('RES-BR-08', 'Solo se inicia la operación de una reserva confirmada, reprogramada o pendiente de pago/proveedor');
        }
        if ($reservation->travel_from->toDateString() > $this->clock->today()->addDay()->toDateString()) {
            throw ApiException::rule('RES-BR-08', 'La operación se inicia desde el día anterior al viaje ('.$reservation->travel_from->copy()->subDay()->toDateString().')');
        }

        return $this->transition($reservation, Reservation::IN_OPERATION, $actor);
    }

    public function complete(Reservation $reservation, User $actor): Reservation
    {
        if ($reservation->status !== Reservation::IN_OPERATION) {
            throw ApiException::rule('RES-BR-08', 'Solo se completa un viaje en operación');
        }
        foreach ($reservation->services()->whereIn('status', [BookedService::CONFIRMED, BookedService::REQUESTED, BookedService::PENDING])->get() as $s) {
            $s->forceFill(['status' => BookedService::COMPLETED])->save();
        }

        return $this->transition($reservation, Reservation::COMPLETED, $actor);
    }

    private function transition(Reservation $reservation, string $to, User $actor): Reservation
    {
        $before = $reservation->status;
        $reservation->forceFill(['status' => $to])->save();
        $this->audit->log('Reserva', $reservation->code, 'cambio_estado', Reservation::STATUS_LABELS[$before], Reservation::STATUS_LABELS[$to], actor: $actor);

        return $reservation->fresh();
    }

    // ── Pasajeros (RES-BR-09/10, RES-V21-04) ──────────────────────────

    /** @return array{0: Passenger, 1: list<string>} pasajero y advertencias */
    public function savePassenger(Reservation $reservation, ?Passenger $passenger, array $data, User $actor): array
    {
        $this->assertOpen($reservation);
        foreach (['full_name' => 'el nombre', 'doc_number' => 'el número de documento', 'nationality' => 'la nacionalidad', 'birth_date' => 'la fecha de nacimiento'] as $field => $label) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                throw ApiException::invalid($field, "Indique {$label} del pasajero (RES-BR-09).");
            }
        }
        $age = (int) floor(Carbon::parse($data['birth_date'])->diffInDays($reservation->travel_from) / 365.25);
        $suggested = $age < 2 ? 'INF' : ($age < 12 ? 'CHD' : 'ADT');
        $type = $data['pax_type'] ?? $passenger?->pax_type ?? $suggested;
        $warnings = $type !== $suggested ? ["Por edad ({$age} años a la fecha del viaje) corresponde {$suggested}; un cambio de tipo que altere el precio se registra como Modificación (RES-BR-09)."] : [];
        if (! empty($data['is_lead'])) {
            $reservation->passengers()->where('is_lead', true)->when($passenger, fn ($q) => $q->whereKeyNot($passenger->id))->update(['is_lead' => false]);
        }
        $values = [
            'full_name' => trim($data['full_name']), 'doc_type' => $data['doc_type'] ?? 'DNI', 'doc_number' => $data['doc_number'], 'pax_type' => $type,
            'nationality' => $data['nationality'], 'birth_date' => $data['birth_date'], 'age' => $age, 'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null, 'relationship' => $data['relationship'] ?? null, 'is_lead' => (bool) ($data['is_lead'] ?? false),
            'diet_notes' => $data['diet_notes'] ?? null, 'is_placeholder' => false,
        ];
        if ($passenger) {
            $before = $passenger->full_name;
            $passenger->fill($values)->save();
            $this->audit->log('Reserva', $reservation->code, 'editar_pasajero', $before, "{$passenger->full_name} · {$type}".($passenger->relationship ? " · {$passenger->relationship}" : '').($passenger->is_lead ? ' · titular' : ''), actor: $actor);
        } else {
            $passenger = Passenger::create($values + ['reservation_id' => $reservation->id, 'seq' => (int) $reservation->passengers()->max('seq') + 1]);
            $warnings[] = 'Agregar pasajeros aquí no recalcula precio ni cupos; para el impacto use Modificar (RES-BR-10).';
            $this->audit->log('Reserva', $reservation->code, 'agregar_pasajero', null, "{$passenger->full_name} · {$type}", actor: $actor);
        }
        $reservation->touch();

        return [$passenger->fresh(), $warnings];
    }

    public function deletePassenger(Reservation $reservation, Passenger $passenger, User $actor): void
    {
        $this->assertOpen($reservation);
        $passenger->delete();
        $reservation->touch();
        $this->audit->log('Reserva', $reservation->code, 'eliminar_pasajero', $passenger->full_name, null, actor: $actor);
    }

    // ── Reasignación (RES-BR-51) ───────────────────────────────────────

    public function reassign(Reservation $reservation, string $ownerId, ?string $note, User $actor): Reservation
    {
        $owner = User::where('status', User::ACTIVE)->find($ownerId) ?? throw ApiException::invalid('owner_id', 'Seleccione un asesor activo.');
        if ($owner->id === $reservation->owner_id) {
            throw ApiException::invalid('owner_id', 'La reserva ya pertenece a ese asesor.');
        }
        $before = $reservation->owner?->name;
        $reservation->forceFill(['owner_id' => $owner->id, 'sede_id' => $owner->sede_id ?? $reservation->sede_id])->save();
        $this->audit->log('Reserva', $reservation->code, 'reasignar', $before, $owner->name.($note ? " · {$note}" : ''), actor: $actor);
        $this->notify->push('reserva.reasignada', 'portal', $owner, "Se le asignó la reserva {$reservation->code}", $reservation->title,
            ['related_type' => 'reservation', 'related_id' => $reservation->id]);
        ReservationReassigned::dispatch($reservation->id);

        return $reservation->fresh();
    }

    // ── Utilidades compartidas ────────────────────────────────────────

    public function assertOpen(Reservation $reservation): void
    {
        if ($reservation->isClosed()) {
            throw ApiException::rule('RES-BR-07', 'La reserva está '.Reservation::STATUS_LABELS[$reservation->status].': no admite cambios');
        }
    }

    /** @param list<array> $rows filas de ServiceBreakdown */
    public function persistServices(Reservation $reservation, array $rows, Product $product, string $date, int $pax, ?string $departureId, ?string $quoteItemId, ?string $altOf = null): Collection
    {
        $departureRef = $departureId ? Departure::whereKey($departureId)->value('ref') : null;

        return collect($rows)->map(fn (array $row, int $n) => BookedService::create([
            'reservation_id' => $reservation->id, 'code' => $this->sequences->next('booking_service'), 'product_id' => $product->id,
            'catalog_service_id' => $row['catalog_service_id'], 'quote_item_id' => $quoteItemId, 'departure_id' => $departureId,
            'provider_id' => $row['provider_id'], 'name' => $row['name'].($departureRef && $n === 0 ? " · {$departureRef}" : ''),
            'service_date' => $date, 'service_time' => $row['time'], 'pax' => $pax, 'status' => BookedService::PENDING,
            'cost_amount' => $row['cost'], 'price_amount' => $row['price'], 'is_required' => $row['is_required'], 'alt_of_id' => $altOf,
        ]));
    }

    /** §4.3 paso 13: fichas "Pasajero ADT 1 (por completar)". */
    public function placeholders(Reservation $reservation, int $adt, int $chd, int $inf, string $only = ''): void
    {
        $seq = (int) $reservation->passengers()->max('seq');
        $counts = $only ? [$only => $adt] : ['ADT' => $adt, 'CHD' => $chd, 'INF' => $inf];
        foreach ($counts as $type => $count) {
            $existing = $reservation->passengers()->where('pax_type', $type)->count();
            for ($i = 1; $i <= $count; $i++) {
                Passenger::create(['reservation_id' => $reservation->id, 'seq' => ++$seq, 'full_name' => "Pasajero {$type} ".($existing + $i).' (por completar)',
                    'pax_type' => $type, 'is_placeholder' => true]);
            }
        }
    }

    /** §4.3 paso 12: Corporativo/B2B por segmento (Grupos → Corporativo); el resto Directo. */
    public static function channelFor(Client $client): string
    {
        return QuotePricing::SEGMENT_CHANNEL[$client->segment_code ?? ''] ?? 'Directo';
    }

    public static function policyOf(Product $product): array
    {
        $p = $product->policies;

        return ['product' => $product->ref, 'cancel' => $p?->cancellation, 'modify' => $p?->modification, 'noshow' => $p?->no_show,
            'refund' => $p?->refund, 'advanceMin' => $p?->advance_min, 'advanceMax' => $p?->advance_max];
    }

    private function notifyConfirmed(Reservation $reservation, ?User $owner): void
    {
        $data = ['client_id' => $reservation->client_id, 'related_type' => 'reservation', 'related_id' => $reservation->id];
        if ($email = $reservation->client?->email) {
            $this->notify->push('reserva.confirmada', 'email', $email, "Su reserva {$reservation->code} está confirmada",
                "{$reservation->title} · {$reservation->travel_from->toDateString()} al {$reservation->travel_to->toDateString()}", $data);
        }
        if ($owner) {
            $this->notify->push('reserva.confirmada', 'push', $owner, "Reserva {$reservation->code} confirmada", $reservation->title, $data);
        }
    }
}
