<?php

namespace App\Services\Catalog\Booking;

use App\Domain\Bookings\AvailabilityCache;
use App\Domain\Bookings\AvailabilityCalculator;
use App\Domain\Bookings\BookingEngine;
use App\Domain\Bookings\QuoteResult;
use App\Domain\Bookings\QuoteSelection;
use App\Domain\Bookings\QuoteToken;
use App\Events\Bookings\ReservationCreated;
use App\Exceptions\ApiException;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Departure;
use App\Models\Catalog\Hold;
use App\Models\Catalog\PickupPoint;
use App\Models\Catalog\Product;
use App\Models\Catalog\Rate;
use App\Models\Crm\Client;
use App\Models\Experience\Currency;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Bookings\ReceivableService;
use App\Services\Bookings\ReservationService;
use App\Services\Bookings\ServiceBreakdown;
use App\Services\Channels\ChannelReservations;
use App\Services\Channels\EcomCheckout;
use App\Services\Crm\ClientService;
use App\Services\Crm\CrmClock;
use App\Services\Experience\WidgetService;
use App\Services\SequenceService;
use App\Services\Site\SiteCatalog;
use App\Services\Site\SitePricing;
use App\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * POST /public/bookings (spec 16 §6.1, §8.4): hold + reserva `pendiente_pago` a partir de un `quote_token`. Recalcula
 * disponibilidad y precio sin caché, con lock Redis por producto/fecha y SELECT … FOR UPDATE de rangos y salida;
 * 409 AVAIL_CHANGED / QUOTE_CHANGED si algo cambió. Guarda el snapshot `booking` (PRD-BR-008/009).
 */
class PublicBookingService
{
    public function __construct(
        private readonly BookingEngine $engine,
        private readonly QuoteToken $tokens,
        private readonly AvailabilityCache $cache,
        private readonly SitePricing $pricing,
        private readonly EcomCheckout $ecom,
        private readonly ChannelReservations $builder,
        private readonly ReservationService $reservations,
        private readonly ReceivableService $receivables,
        private readonly ClientService $clients,
        private readonly SequenceService $sequences,
        private readonly TenantContext $tenant,
        private readonly CrmClock $clock,
        private readonly AuditLogger $audit,
    ) {}

    public function book(array $data): array
    {
        $token = $this->tokens->verify((string) ($data['quote_token'] ?? ''));
        $product = Product::whereKey($token['product_id'])->where('status', Product::ACTIVE)->first() ?? throw ApiException::notFound();
        $sel = $token['sel'];
        if (! $sel->date) {
            throw ApiException::invalid('date', 'Elija la fecha del servicio.');
        }
        $contact = $this->contact((array) ($data['contact'] ?? []));
        $pickup = $this->pickup($product, (array) ($data['pickup'] ?? []));
        $comments = $this->engine->rules->on('R-COMMENTS', $product) ? mb_substr(trim((string) ($data['comments'] ?? '')), 0, 1000) : '';

        $lock = Cache::lock("avail:{$this->tenant->id()}:{$product->id}:{$sel->date}", 10);
        try {
            return $lock->block(5, fn () => DB::transaction(function () use ($product, $sel, $token, $contact, $pickup, $comments, $data) {
                $result = $this->create($product, $sel, $token, $contact, $pickup, $comments);
                // Spec 17: traspaso del widget (WHO-) y moneda en que el cliente vio el precio (regla transversal 15).
                $handoff = ! empty($data['handoff']) ? app(WidgetService::class)->converted((string) $data['handoff'], $result['reservation_id']) : null;
                $display = strtoupper((string) ($data['currency_display'] ?? $handoff?->currency_display ?? $result['currency']));
                if (Currency::where('code', $display)->where('active', true)->exists()) {
                    Reservation::whereKey($result['reservation_id'])->update(['currency_display' => $display]);
                    $result['currency_display'] = $display;
                }
                if ($handoff) {
                    $result['handoff'] = $handoff->code;
                }
                unset($result['reservation_id']);

                return $result;
            }));
        } catch (LockTimeoutException) {
            throw ApiException::conflict('AVAIL_BUSY', 'Hay otra reserva en curso para esa fecha. Intente de nuevo en unos segundos.');
        }
    }

    private function create(Product $product, QuoteSelection $sel, array $token, array $contact, ?array $pickup, string $comments): array
    {
        $seats = $sel->seats();
        $day = $this->engine->dayStatus($product, $sel->date, $seats, lock: true);
        if (! $day->bookable($seats)) {
            throw ApiException::conflict('AVAIL_CHANGED', "La fecha ya no tiene cupo para {$seats} pasajero(s) ({$day->label}).", [
                'day' => $day->toPublic(), 'next_available' => $this->engine->nextAvailable($product, $seats, CarbonImmutable::parse($sel->date)),
            ]);
        }
        $quote = $this->engine->quote($product, $sel);
        if (! $quote->ok()) {
            throw ApiException::unprocessable('QUOTE_INVALID', 'La selección no cumple las reglas de reserva.', ['warnings' => $quote->warnings]);
        }
        if (! in_array($quote->currency, ['PEN', 'USD'], true)) {
            throw ApiException::unprocessable('CURRENCY_NOT_CHARGE', "La reserva se cobra en PEN o USD; {$quote->currency} es solo de visualización.");
        }
        if (! BigDecimal::of($quote->total)->isEqualTo($token['total']) || $quote->currency !== $token['currency']) {
            throw ApiException::conflict('QUOTE_CHANGED', "El precio cambió: ahora es {$quote->currency} {$quote->total}.", ['quote' => $quote->toArray()]);
        }
        $owner = $this->ecom->owner();
        $client = $this->client($contact, $owner);
        $fx = $this->pricing->usdRate();
        $days = SiteCatalog::days($product);
        $snapshot = [
            'engine' => 'v2.1', 'product' => ['id' => $product->id, 'ref' => $product->ref, 'name' => $product->name], 'date' => $sel->date, 'pax' => ['adt' => $sel->adt, 'chd' => $sel->chd, 'inf' => $sel->inf],
            'lines' => array_values(array_filter($quote->lines, fn ($l) => ! $l['removed'])), 'adjustments' => $quote->adjustments,
            'lodging' => array_values(array_map(fn ($l) => $l['meta'] + ['qty' => $l['qty'], 'amount' => $l['amount']], array_filter($quote->lines, fn ($l) => $l['kind'] === 'lodging' && ! $l['removed']))),
            'addons' => array_values(array_map(fn ($l) => $l['meta'] + ['label' => $l['label'], 'qty' => $l['qty'], 'unit' => $l['unit'], 'amount' => $l['amount']], array_filter($quote->lines, fn ($l) => $l['kind'] === 'addon' && ! $l['removed']))),
            'pickup' => $pickup, 'comments' => $comments ?: null,
            'payment' => ['mode' => $quote->paymentMode, 'deposit_pct' => $quote->depositPct, 'deposit' => $quote->deposit, 'balance' => $quote->balance, 'balance_due' => $quote->balanceDue, 'hold_hours' => $quote->holdHours],
            'subtotal' => $quote->subtotal, 'total' => $quote->total, 'currency' => $quote->currency, 'fxRate' => $quote->fxRate, 'rulesApplied' => $quote->rulesApplied,
            'contact' => ['name' => $contact['name'], 'email' => $contact['email'], 'phone' => $contact['phone']],
        ];
        $reservation = Reservation::create([
            'code' => $this->sequences->next('reservation'), 'client_id' => $client->id, 'owner_id' => $owner->id, 'sede_id' => $owner->sede_id,
            'title' => mb_substr("{$product->name} — {$contact['name']} (reserva online)", 0, 200), 'status' => Reservation::PENDING_PAYMENT, 'channel' => 'E-commerce',
            'travel_from' => $sel->date, 'travel_to' => CarbonImmutable::parse($sel->date)->addDays($days - 1)->toDateString(),
            'currency' => $quote->currency, 'fx_rate' => $fx, 'total_amount' => $quote->total,
            'snapshot_label' => "{$product->ref} v".($product->currentVersion?->version ?? '1.0').' · motor de reservas',
            'policies_snapshot' => $product->policies ? [$product->id => ReservationService::policyOf($product)] : [],
            'booking' => $snapshot,
        ]);
        $services = $this->services($reservation, $product, $sel, $quote, $day->departure ? $this->departureId($product, $sel->date) : null, $fx);
        $this->builder->setServices($reservation, Reservation::PENDING_PAYMENT, $owner, $services);
        $this->reservations->placeholders($reservation, $sel->adt, $sel->chd, $sel->inf);
        $this->charge($reservation, $product, $quote, $owner, $fx);

        $minutes = $quote->paymentMode === 'pagar_despues' ? (int) $quote->holdHours * 60 : (int) $this->engine->rules->param('R-HOLD', 'minutes', null, 30);
        $hold = Hold::create([
            'ref' => $this->sequences->next('catalog_hold'), 'product_id' => $product->id, 'service_date' => $sel->date,
            'departure_id' => $day->departure ? $this->departureId($product, $sel->date) : null, 'quantity' => max(1, $seats),
            'reference' => $reservation->code, 'reference_type' => AvailabilityCalculator::ENGINE_HOLD, 'reference_id' => $reservation->id,
            'status' => 'hold', 'expires_at' => now()->addMinutes(max(5, $minutes)),
        ]);
        $this->audit->log('Reserva', $reservation->code, 'crear_motor', null,
            "Pendiente de pago · {$product->ref} · {$sel->date} · {$sel->adt} ADT/{$sel->chd} CHD/{$sel->inf} INF · {$quote->currency} {$quote->total} · {$quote->paymentMode} · hold {$hold->ref}", system: true);
        ReservationCreated::dispatch($reservation->id);
        $this->cache->bump($product);

        return [
            'reservation' => $reservation->code, 'reservation_id' => $reservation->id, 'status' => $reservation->status, 'product' => $product->ref, 'date' => $sel->date,
            'total' => $quote->total, 'currency' => $quote->currency, 'payment_mode' => $quote->paymentMode, 'deposit' => $quote->deposit, 'balance' => $quote->balance,
            'balance_due' => $quote->balanceDue, 'hold' => ['ref' => $hold->ref, 'expires_at' => $hold->expires_at->toIso8601String()], 'warnings' => $quote->warnings,
        ];
    }

    /** Servicios: base por componentes del producto, adicionales y alojamiento (una fila por línea). @return list<BookedService> */
    private function services(Reservation $reservation, Product $product, QuoteSelection $sel, QuoteResult $quote, ?string $departureId, string $fx): array
    {
        $pax = max(1, $sel->seats());
        $base = BigDecimal::zero();
        $extras = [];
        foreach ($quote->lines as $l) {
            if ($l['removed'] || $l['kind'] === 'info') {
                continue;
            }
            if ($l['kind'] === 'base') {
                $base = $base->plus($l['amount']);
            } else {
                $extras[] = $l;
            }
        }
        $adjust = BigDecimal::of($quote->total)->minus($quote->subtotal);
        $base = BigDecimal::max(BigDecimal::zero(), $base->plus($adjust));
        $rate = $quote->toArray()['rate_id'] ? Rate::find($quote->toArray()['rate_id']) : null;
        $cost = $rate ? $this->pricing->convert(BigDecimal::of((string) $rate->cost)->multipliedBy($pax), $rate->currency, $quote->currency, $fx) : '0';
        $rows = ServiceBreakdown::splitTotals($product, $pax, $cost, (string) $base->toScale(2, RoundingMode::HalfUp), $quote->currency, $fx);
        $services = $this->reservations->persistServices($reservation, $rows, $product, $sel->date, $pax, $departureId, null)->all();
        foreach ($extras as $l) {
            $services[] = BookedService::create(BookedService::kindFor($l['kind'] === 'lodging' ? 'Alojamiento' : null) + [
                'reservation_id' => $reservation->id, 'code' => $this->sequences->next('booking_service'), 'product_id' => $product->id,
                'name' => mb_substr($l['label'].($l['qty'] > 1 ? " × {$l['qty']}" : ''), 0, 200), 'service_date' => $sel->date, 'pax' => $pax, 'status' => BookedService::PENDING,
                'cost_amount' => '0.00', 'price_amount' => $l['amount'], 'is_required' => false, 'departure_id' => $departureId,
            ]);
        }

        return $services;
    }

    /** CxC según la modalidad: total; adelanto + saldo; pagar después (todo, vence al terminar el hold). */
    private function charge(Reservation $reservation, Product $product, QuoteResult $quote, User $owner, string $fx): void
    {
        $today = $this->clock->today()->toDateString();
        $label = "reserva online {$reservation->code} — {$product->name}";
        match ($quote->paymentMode) {
            'adelanto' => [
                $this->receivables->create($reservation, "Adelanto {$quote->depositPct} % — {$label}", $quote->deposit, $today, $owner, 'motor de reservas', 'anticipo', $fx),
                $this->receivables->create($reservation, "Saldo — {$label}", $quote->balance, (string) $quote->balanceDue, $owner, 'motor de reservas', 'saldo', $fx),
            ],
            default => $this->receivables->create($reservation, ($quote->paymentMode === 'pagar_despues' ? 'Pago (reservado sin pago) — ' : 'Pago total — ').$label,
                $quote->total, $today, $owner, 'motor de reservas', 'saldo', $fx),
        };
    }

    private function departureId(Product $product, string $date): ?string
    {
        return Departure::where('product_id', $product->id)->whereDate('departure_date', $date)->whereIn('status', ['abierta', 'completa'])
            ->orderBy('departure_time')->value('id');
    }

    private function contact(array $c): array
    {
        $name = trim((string) ($c['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($c['email'] ?? '')));
        $phone = trim((string) ($c['phone'] ?? ''));
        $docType = (string) ($c['doc_type'] ?? 'DNI');
        $doc = strtoupper(trim((string) ($c['doc_number'] ?? '')));
        $errors = [];
        if (mb_strlen($name) < 3) {
            $errors['contact.name'] = ['Indique el nombre del titular.'];
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['contact.email'] = ['Correo inválido.'];
        }
        if (! preg_match('/^\+?[0-9 ]{7,20}$/', $phone)) {
            $errors['contact.phone'] = ['Teléfono inválido.'];
        }
        if (! in_array($docType, ['DNI', 'CE', 'Pasaporte'], true) || ! preg_match('/^[A-Z0-9]{6,15}$/', $doc)) {
            $errors['contact.doc_number'] = ['Documento inválido.'];
        }
        if ($errors) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'VALIDATION_ERROR', 'Revise los datos del titular.', [], ['errors' => $errors]);
        }

        return ['name' => $name, 'email' => $email, 'phone' => $phone, 'doc_type' => $docType, 'doc_number' => $doc, 'country' => trim((string) ($c['country'] ?? 'Perú')) ?: 'Perú'];
    }

    /** R-PICKUP: punto obligatorio si el producto lo exige; dirección para «Hotel en…»; vuelo si se pide. */
    private function pickup(Product $product, array $data): ?array
    {
        if (! $this->engine->rules->on('R-PICKUP', $product)) {
            return null;
        }
        $points = PickupPoint::where('product_id', $product->id)->orderBy('sequence')->get();
        $id = (string) ($data['point_id'] ?? '');
        $point = Str::isUuid($id) ? $points->firstWhere('id', $id) : null;
        if (! $point) {
            if ($product->pickup_required && $points->isNotEmpty()) {
                throw ApiException::invalid('pickup.point_id', 'Elija el punto de recojo.');
            }

            return null;
        }
        $address = trim((string) ($data['address'] ?? ''));
        if ($point->requires_address && $address === '') {
            throw ApiException::invalid('pickup.address', 'Indique la dirección del hotel.');
        }

        return ['point_id' => $point->id, 'label' => $point->label, 'kind' => $point->kind, 'address' => $address ?: null,
            'flight' => $product->pickup_ask_flight ? (trim((string) ($data['flight'] ?? '')) ?: null) : null];
    }

    private function client(array $c, User $owner): Client
    {
        if ($found = Client::where('email_norm', $c['email'])->orderBy('created_at')->first()) {
            return $found;
        }
        $docType = ['DNI' => 'DNI', 'CE' => 'CE', 'Pasaporte' => 'PASAPORTE'][$c['doc_type']];
        $client = Client::where('doc_number', $c['doc_number'])->first() ?? $this->clients->create(['kind' => 'persona', 'name' => $c['name'], 'doc_type' => $docType,
            'doc_number' => $c['doc_number'], 'email' => $c['email'], 'phone' => $c['phone'], 'country' => $c['country'], 'segment_code' => 'retail',
            'tags' => ['Web', 'Reserva online'], 'owner_id' => $owner->id], $owner, true);
        $this->audit->log('Cliente', $client->code, 'crear_desde_motor_reservas', null, $c['email'], system: true);

        return $client;
    }
}
