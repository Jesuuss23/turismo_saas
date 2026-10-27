<?php

namespace App\Services\Channels;

use App\Exceptions\ApiException;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Departure;
use App\Models\Catalog\Product;
use App\Models\Channel\B2bAgency;
use App\Models\Channel\B2bSettlementRequest;
use App\Models\Finance\Commission;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentMethod;
use App\Models\Finance\Receivable;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Bookings\BookingInventory;
use App\Services\Catalog\AvailabilityService;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Crm\CrmClock;
use App\Services\Finance\Fin;
use App\Services\Finance\FxRates;
use App\Services\Notify;
use App\Services\Quotes\QuotePricing;
use App\Services\SequenceService;
use App\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Portal de agencias (spec 08 §6.2, PROC-053): catálogo autorizado (RT-14), reserva con crédito o prepago (B2B-BR-03…13),
 * estado de cuenta, informe de pago (B2B-BR-14) y solicitud de liquidación de comisiones (B2B-BR-15).
 */
class B2bPortal
{
    public function __construct(
        private readonly B2bTerms $terms,
        private readonly B2bCredit $credit,
        private readonly ChannelReservations $builder,
        private readonly AvailabilityService $availability,
        private readonly BookingInventory $inventory,
        private readonly QuotePricing $pricing,
        private readonly CrmClock $clock,
        private readonly FxRates $fx,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
        private readonly TenantContext $tenant,
    ) {}

    public function me(B2bAgency $a): array
    {
        $status = $a->effectiveStatus();

        return [
            'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'ruc' => $a->ruc, 'contact_name' => $a->contact_name, 'booking_email' => $a->booking_email,
            'status' => $status, 'status_label' => B2bAgency::STATUS_LABELS[$status], 'price_list' => $a->priceList?->name,
            'commission_pct' => (string) $a->commission_pct, 'credit_currency' => $a->credit_currency, 'credit_limit' => (string) $a->credit_limit,
            'credit_used' => (string) $a->credit_used, 'credit_available' => $a->available(), 'valid_from' => $a->valid_from?->toDateString(),
            'valid_to' => $a->valid_to?->toDateString(), 'credit_enabled' => (float) $a->credit_limit > 0 && $status === 'activa',
            'notice' => app(B2bAgencyService::class)->restriction($a),
            'expiring_days' => $a->valid_to && $status !== 'vencido' ? $this->clock->today()->diffInDays($a->valid_to, false) : null,
        ];
    }

    /** B2B-BR-01: reservables primero; con PVP, neto, comisión, crédito y próximas salidas. */
    public function catalog(B2bAgency $agency): array
    {
        $products = $this->terms->authorizedProducts()->with(['primaryDestination', 'category'])->orderBy('name')->get();

        return $products->map(fn (Product $p) => $this->productRow($agency, $p))
            ->sortBy(fn (array $r) => ($r['ok'] ? '0' : '1').$r['name'])->values()->all();
    }

    public function productRow(B2bAgency $agency, Product $p): array
    {
        $t = $this->terms->terms($agency, $p);
        $bySalida = BookingInventory::bySalida($p);
        $departures = $bySalida ? $this->departures($p, 3) : [];

        return [
            'id' => $p->id, 'ref' => $p->ref, 'name' => $p->name, 'destination' => $p->primaryDestination?->name, 'category' => $p->category?->name,
            'duration' => "{$p->duration_value} {$p->duration_unit}", 'min_pax' => $p->minimum_passengers, 'max_pax' => $p->maximum_passengers,
            'advance_min_days' => $p->booking_advance_min_days, 'by_departure' => $bySalida,
            'ok' => $t['ok'], 'reason' => $t['reason'], 'net' => $t['net'], 'currency' => $t['currency'], 'pvp' => $t['pvp'], 'pvp_currency' => $t['pvp_currency'],
            'commission_pct' => $t['commission_pct'], 'commission' => $t['net'] !== null ? Fin::money((float) $t['net'] * (float) $t['commission_pct'] / 100) : null,
            'credit' => $t['credit'], 'valid_to' => $t['valid_to'], 'conditions' => $t['rate']?->conditions, 'restrictions' => $t['rate']?->restrictions,
            'departures' => $departures,
        ];
    }

    /** Salidas futuras con cupos y marca "fuera de anticipación" (B2B-BR-06). */
    public function departures(Product $p, int $limit = 30): array
    {
        $today = $this->clock->today();
        $min = $today->copy()->addDays($p->booking_advance_min_days)->toDateString();

        return Departure::where('product_id', $p->id)->whereIn('status', ['abierta', 'completa'])->whereDate('departure_date', '>=', $today->toDateString())
            ->orderBy('departure_date')->orderBy('departure_time')->limit($limit)->get()
            ->map(fn (Departure $d) => ['id' => $d->id, 'ref' => $d->ref, 'date' => $d->departure_date->toDateString(), 'time' => substr((string) $d->departure_time, 0, 5),
                'capacity' => $d->capacity, 'available' => $this->availability->available($d), 'status' => $d->status,
                'closed' => $d->departure_date->toDateString() < $min, 'meeting_point' => $d->meeting_point])->all();
    }

    /** Simulación (sin efectos): total, comisión, equivalente PEN y crédito tras la reserva. */
    public function quotePrice(B2bAgency $agency, array $data): array
    {
        return $this->plan($agency, $data)['price'];
    }

    /** POST /b2b/reservations (Idempotency-Key en la ruta; lock de la salida + FOR UPDATE de la agencia). */
    public function reserve(B2bAgency $agency, array $data, User $actor): Reservation
    {
        $plan = $this->plan($agency, $data);
        /** @var Product $product */
        $product = $plan['product'];
        $departure = $plan['departure'];
        $mode = $plan['mode'];
        $price = $plan['price'];

        return $this->availability->lockMany($departure ? [$departure->id] : [], function (Collection $locked) use ($agency, $data, $actor, $product, $departure, $mode, $price, $plan) {
            $agency = $this->credit->lock($agency);
            $departure = $departure ? $locked[$departure->id] : null;
            if ($departure) {
                $free = $this->availability->available($departure);
                if ($free < $price['pax']) {
                    throw ApiException::rule('RT-4', "Sin disponibilidad: la salida {$departure->ref} tiene {$free} cupo(s) libre(s) para {$price['pax']} pasajero(s). Elija otra salida.");
                }
            }
            if ($mode === 'credito' && $price['line_amount'] > (float) $agency->available() + Fin::EPS) {
                $this->audit->isolated('Agencia B2B', $agency->code, 'credito_excedido', "disponible {$agency->credit_currency} {$agency->available()}",
                    "intento {$agency->credit_currency} ".Fin::money($price['line_amount'])." · {$product->ref}", actor: $actor);
                throw ApiException::unprocessable('B2B-BR-04', "Crédito insuficiente: disponible {$agency->credit_currency} {$agency->available()} y la reserva requiere {$agency->credit_currency} ".Fin::money($price['line_amount']).'; el sistema bloquea la reserva a crédito. Puede cambiar a prepago.',
                    ['can_prepay' => true]);
            }
            $status = match (true) {
                $mode === 'prepago' => Reservation::PENDING_PAYMENT,
                $departure !== null => Reservation::CONFIRMED,
                default => Reservation::PENDING_PROVIDER,
            };
            $today = $this->clock->today();
            $days = $mode === 'credito' ? (int) data_get($this->tenant->tenant()?->settings, 'channels.b2b_credit_days', 15) : 1;
            $ref = trim((string) ($data['agency_ref'] ?? '')) ?: null;
            [$reservation, $receivable] = $this->builder->create([
                'product' => $product, 'departure' => $departure, 'date' => $plan['date'], 'adt' => $price['pax'], 'chd' => 0,
                'client_id' => $agency->client_id ?? throw ApiException::rule('B2B-BR-17', 'La agencia no tiene cliente CRM titular.'),
                'owner' => $actor, 'channel' => 'B2B', 'status' => $status, 'currency' => $price['currency'], 'total' => $price['total'], 'cost' => $price['cost'],
                'title' => "{$product->name} — {$agency->name}".($ref ? " ({$ref})" : ''), 'agency_id' => $agency->id,
                'b2b_terms' => ['price_list' => $agency->priceList?->name, 'commission_pct' => $price['commission_pct'], 'pay_mode' => $mode, 'agency_ref' => $ref,
                    'net_unit' => $price['net_unit']],
                'passengers' => $plan['passengers'],
                'receivable' => ['concept' => "Reserva B2B {$product->name} — {$price['pax']} pax".($mode === 'prepago' ? ' (prepago)' : ''),
                    'due' => $today->copy()->addDays($days)->toDateString(), 'kind' => 'b2b'],
                'audit_action' => 'crear_b2b', 'origin' => "portal B2B {$agency->code}",
            ], $actor);
            $commission = Commission::create(['code' => $this->sequences->next('commission'), 'reservation_id' => $reservation->id, 'kind' => 'agencia_b2b',
                'beneficiary_agency_id' => $agency->id, 'rule_description' => "Agencia B2B {$agency->name}: {$price['commission_pct']} % sobre el total neto",
                'base_amount' => $price['total'], 'pct' => $price['commission_pct'], 'amount' => $price['commission'], 'currency' => $price['currency'],
                'fx_rate' => $price['currency'] === 'PEN' ? null : $reservation->fx_rate, 'status' => 'calculada']);
            Fin::history('commission', $commission->id, 'calculada', 'Reserva B2B', $actor);
            if ($mode === 'credito') {
                $this->credit->chargeReservation($agency, $reservation, $actor);
            }
            $vouchers = $status === Reservation::CONFIRMED ? $this->builder->issueVouchers($reservation, $actor, 'b2b') : [];
            $this->notifyReservation($agency, $reservation, $status, count($vouchers));

            return $reservation->fresh();
        });
    }

    /**
     * Validaciones de la reserva y precio (RT-14, B2B-BR-02/03/05/06, RT-4 previo).
     *
     * @return array{product: Product, departure: ?Departure, date: string, mode: string, passengers: list<array>, price: array}
     */
    private function plan(B2bAgency $agency, array $data): array
    {
        $product = $this->terms->authorizedProducts()->with(['policies', 'items.service', 'itinerary.days.items', 'currentVersion'])
            ->where(fn ($q) => $q->where('id', preg_match('/^[0-9a-f-]{36}$/i', (string) ($data['product_id'] ?? '')) ? $data['product_id'] : null)->orWhere('ref', $data['product_id'] ?? ''))->first()
            ?? throw ApiException::invalid('product_id', 'Producto no disponible en su catálogo B2B.');
        $t = $this->terms->terms($agency, $product);
        if (! $t['ok']) {
            throw ApiException::unprocessable('RT-14', $t['reason']);
        }
        $pax = (int) ($data['pax'] ?? 0);
        if ($pax < $product->minimum_passengers || $pax > $product->maximum_passengers) {
            throw ApiException::invalid('pax', "El producto admite entre {$product->minimum_passengers} y {$product->maximum_passengers} pasajeros (B2B-BR-05).");
        }
        $today = $this->clock->today();
        $departure = null;
        if (BookingInventory::bySalida($product)) {
            $departure = Departure::where('product_id', $product->id)->whereIn('status', ['abierta', 'completa'])->find($data['departure_id'] ?? null)
                ?? throw ApiException::invalid('departure_id', 'Elija una salida.');
            $date = $departure->departure_date->toDateString();
        } else {
            $date = (string) ($data['date'] ?? '') ?: throw ApiException::invalid('date', 'Indique la fecha del servicio.');
        }
        $min = $today->copy()->addDays($product->booking_advance_min_days)->toDateString();
        if ($date < $min) {
            throw ApiException::invalid($departure ? 'departure_id' : 'date', "Requiere {$product->booking_advance_min_days} día(s) de anticipación mínima: fecha desde {$min} (B2B-BR-06).");
        }
        if ($departure && $this->availability->available($departure) < $pax) {
            throw ApiException::rule('RT-4', "Sin disponibilidad: la salida {$departure->ref} tiene {$this->availability->available($departure)} cupo(s) libre(s). Elija otra salida.");
        }
        $mode = ($data['pay_mode'] ?? 'credito') === 'prepago' ? 'prepago' : 'credito';
        if ($mode === 'credito' && ! $t['credit']) {
            throw ApiException::unprocessable('B2B-BR-03', $agency->effectiveStatus() === 'bloqueada'
                ? 'Agencia bloqueada: solo puede reservar con prepago (Regla 14).'
                : 'Esta tarifa o su convenio no permiten crédito: reserve con prepago (B2B-BR-03).');
        }
        $net = BigDecimal::of((string) $t['net']);
        $total = $net->multipliedBy($pax)->toScale(2, RoundingMode::HalfUp);
        $commission = $total->multipliedBy((string) $t['commission_pct'])->dividedBy(100, 2, RoundingMode::HalfUp);
        $public = $this->terms->publicRate($product, $date);
        $cost = $public ? BigDecimal::of($this->pricing->suggest($public, $date, $t['currency'], $this->pricing->fx())['unit_cost'])->multipliedBy($pax)->toScale(2) : BigDecimal::zero()->toScale(2);
        [$line] = $this->credit->toLine($agency, (float) (string) $total, $t['currency']);
        $passengers = collect(preg_split('/\R/', (string) ($data['passengers'] ?? '')) ?: [])->map(fn ($l) => trim($l))->filter()->take($pax)
            ->map(function (string $line) {
                [$name, $doc] = array_pad(preg_split('/\s+[—–-]\s+/u', $line, 2) ?: [], 2, null);

                return ['name' => $name, 'doc' => $doc];
            })->values()->all();
        if (is_array($data['passengers'] ?? null)) {
            $passengers = collect($data['passengers'])->take($pax)->map(fn ($p) => ['name' => $p['name'] ?? null, 'doc' => $p['doc'] ?? null])->all();
        }

        return ['product' => $product, 'departure' => $departure, 'date' => $date, 'mode' => $mode, 'passengers' => $passengers, 'price' => [
            'pax' => $pax, 'currency' => $t['currency'], 'net_unit' => (string) $net->toScale(2), 'total' => (string) $total, 'commission_pct' => (string) $t['commission_pct'],
            'commission' => (string) $commission, 'cost' => (string) $cost, 'pen' => Fin::money($this->fx->toPen((string) $total, $t['currency'])),
            'line_amount' => $line, 'credit_currency' => $agency->credit_currency, 'credit_available' => $agency->available(),
            'credit_after' => $mode === 'credito' ? Fin::money((float) $agency->available() - $line) : $agency->available(),
            'credit_ok' => $mode === 'prepago' || $line <= (float) $agency->available() + Fin::EPS, 'mode' => $mode, 'date' => $date,
            'status' => $mode === 'prepago' ? Reservation::PENDING_PAYMENT : ($departure ? Reservation::CONFIRMED : Reservation::PENDING_PROVIDER),
        ]];
    }

    /** B2B-BR-16: reservas B2B de su agencia. */
    public function reservations(B2bAgency $agency)
    {
        return Reservation::where('channel', 'B2B')->where(fn ($q) => $q->where('agency_id', $agency->id)->orWhere('client_id', $agency->client_id))
            ->withCount('passengers')->orderByDesc('created_at');
    }

    /** Estado de cuenta (§2 `cuenta`): CxC, comisiones, pagos y KPIs. */
    public function statement(B2bAgency $agency): array
    {
        $resIds = $this->reservations($agency)->pluck('id');
        $receivables = Receivable::whereIn('reservation_id', $resIds)->where('status', '<>', 'cancelada')->with('reservation:id,code,title')->orderBy('due_date')->get();
        $commissions = Commission::where('beneficiary_agency_id', $agency->id)->with('reservation:id,code')->orderByDesc('created_at')->get();
        $payments = Payment::whereIn('reservation_id', $resIds)->where('kind', 'cobro')->orderByDesc('created_at')->get();
        $pen = fn ($amount, $currency, $fx) => $this->fx->toPen($amount, $currency, $fx);
        $billed = $receivables->sum(fn (Receivable $r) => $pen($r->amount, $r->currency, $r->fx_rate));
        $paid = $receivables->sum(fn (Receivable $r) => $pen($r->paid_amount, $r->currency, $r->fx_rate));
        $favor = $commissions->where('status', '<>', 'pagada')->sum(fn (Commission $c) => $pen($c->amount, $c->currency, $c->fx_rate));
        $settlements = B2bSettlementRequest::where('agency_id', $agency->id)->orderByDesc('created_at')->get();

        return [
            'kpis' => ['billed' => Fin::money($billed), 'paid' => Fin::money($paid), 'due' => Fin::money($billed - $paid), 'commissions' => Fin::money($favor),
                'net' => Fin::money($billed - $paid - $favor)],
            'receivables' => $receivables->map(fn (Receivable $r) => ['id' => $r->id, 'code' => $r->code, 'reservation' => $r->reservation?->code, 'concept' => $r->concept,
                'amount' => (string) $r->amount, 'paid' => (string) $r->paid_amount, 'balance' => $r->balance(), 'currency' => $r->currency, 'due_date' => $r->due_date->toDateString(),
                'status' => $r->status, 'status_label' => Receivable::STATUS_LABELS[$r->status],
                'commission' => (string) ($commissions->firstWhere('reservation_id', $r->reservation_id)?->amount ?? '0.00'),
                'reported' => $payments->where('receivable_id', $r->id)->where('status', 'pendiente')->sum('amount') > 0])->all(),
            'commissions' => $commissions->map(fn (Commission $c) => ['code' => $c->code, 'reservation' => $c->reservation?->code, 'amount' => (string) $c->amount,
                'currency' => $c->currency, 'pct' => (string) $c->pct, 'status' => $c->status, 'status_label' => Commission::STATUS_LABELS[$c->status],
                'requested' => $c->settlement_request_id !== null])->all(),
            'payments' => $payments->map(fn (Payment $p) => ['code' => $p->code, 'method' => $p->method, 'amount' => (string) $p->amount, 'currency' => $p->currency,
                'paid_on' => $p->paid_on->toDateString(), 'reference' => $p->reference, 'status' => $p->status,
                'status_label' => $p->status === 'pendiente' ? 'Por validar' : (Payment::STATUS_LABELS[$p->status] ?? $p->status), 'reported' => (bool) $p->reported_by_agency])->all(),
            'settlements' => $settlements->map(fn (B2bSettlementRequest $s) => ['code' => $s->code, 'amount_pen' => (string) $s->amount_pen, 'status' => $s->status,
                'status_label' => B2bSettlementRequest::STATUS_LABELS[$s->status], 'at' => $s->created_at->toIso8601String(), 'count' => count($s->commission_ids)])->all(),
            'can_settle' => $commissions->contains(fn (Commission $c) => $c->status !== 'pagada' && $c->settlement_request_id === null),
            'methods' => PaymentMethod::where('active', true)->where('is_b2b_credit', false)->where('requires_reference', true)->orderBy('label')->pluck('label')->all(),
        ];
    }

    /** B2B-BR-14: pago `pendiente` por validar; el crédito se libera cuando Finanzas lo aplica. */
    public function reportPayment(B2bAgency $agency, Receivable $receivable, array $data, User $actor): Payment
    {
        $amount = Fin::r2($data['amount'] ?? 0);
        $operation = trim((string) ($data['operation_number'] ?? ''));
        if ($amount <= 0) {
            throw ApiException::invalid('amount', 'El monto debe ser mayor a 0.');
        }
        if ($operation === '') {
            throw ApiException::invalid('operation_number', 'Indique el N.° de operación.');
        }
        $method = PaymentMethod::where('active', true)->where('is_b2b_credit', false)->where(fn ($q) => $q->where('label', $data['method'] ?? '')->orWhere('code', $data['method'] ?? ''))->first()
            ?? throw ApiException::invalid('method', 'Elija el medio de pago.');

        return DB::transaction(function () use ($agency, $receivable, $amount, $operation, $method, $actor) {
            $receivable = Receivable::whereKey($receivable->id)->lockForUpdate()->firstOrFail();
            if (! in_array($receivable->status, Receivable::OPEN, true) || $amount > (float) $receivable->balance() + Fin::EPS) {
                throw ApiException::unprocessable('B2B-BR-14', "El monto debe ser mayor a 0 y no superar el saldo ({$receivable->currency} {$receivable->balance()}).");
            }
            $payment = Payment::create([
                'code' => $this->sequences->next('payment'), 'receivable_id' => $receivable->id, 'reservation_id' => $receivable->reservation_id, 'client_id' => $receivable->client_id,
                'kind' => 'cobro', 'source' => 'b2b', 'method' => $method->label, 'amount' => Fin::money($amount), 'currency' => $receivable->currency,
                'fx_rate' => $receivable->currency === 'PEN' ? null : $this->fx->rate($receivable->currency), 'paid_on' => $this->clock->today()->toDateString(),
                'reference' => $operation, 'note' => "Informado por la agencia {$agency->code}", 'status' => 'pendiente', 'registered_by' => $actor->id, 'reported_by_agency' => true,
            ]);
            Fin::history('payment', $payment->id, 'pendiente', "Informado por {$agency->name}", $actor);
            $this->audit->log('Pago', $payment->code, 'informar_b2b', null, "Por validar · {$payment->method} · {$payment->currency} {$payment->amount} · op. {$operation} · {$receivable->code}", actor: $actor);
            foreach (CatalogRecipients::role('finance') as $u) {
                $this->notify->push('pago.informado', 'portal', $u, "Pago informado por {$agency->name}: {$payment->currency} {$payment->amount}",
                    "{$receivable->code} · op. {$operation} · por validar", ['related_type' => 'payment', 'related_id' => $payment->id, 'client_id' => $receivable->client_id]);
            }

            return $payment;
        });
    }

    /** B2B-BR-15: solo con comisiones no pagadas ni ya solicitadas. */
    public function requestSettlement(B2bAgency $agency, User $actor): B2bSettlementRequest
    {
        return DB::transaction(function () use ($agency, $actor) {
            $commissions = Commission::where('beneficiary_agency_id', $agency->id)->where('status', '<>', 'pagada')->whereNull('settlement_request_id')->lockForUpdate()->get();
            if ($commissions->isEmpty()) {
                throw ApiException::unprocessable('B2B-BR-15', 'No hay comisiones pendientes de liquidar.');
            }
            $fx = $this->fx->rate('USD');
            $amount = $commissions->sum(fn (Commission $c) => $this->fx->toPen($c->amount, $c->currency, $c->fx_rate));
            $request = B2bSettlementRequest::create(['code' => $this->sequences->next('b2b_settlement'), 'agency_id' => $agency->id, 'amount_pen' => Fin::money($amount),
                'fx_rate' => $fx, 'commission_ids' => $commissions->pluck('id')->all(), 'status' => 'solicitada', 'requested_by' => $actor->id]);
            Commission::whereIn('id', $commissions->pluck('id'))->update(['settlement_request_id' => $request->id]);
            foreach ($commissions as $c) {
                $this->audit->log('Comisión', $c->code, 'solicitar_liquidacion', null, "{$request->code} · {$c->currency} {$c->amount}", actor: $actor);
            }
            $this->audit->log('Liquidación B2B', $request->code, 'crear', null, "{$agency->name} · {$commissions->count()} comisión(es) · PEN {$request->amount_pen}", actor: $actor);
            $to = (string) data_get($this->tenant->tenant()?->settings, 'channels.finance_email', 'pleon@andes.test');
            $this->notify->push('comision.liquidacion', 'email', $to, "Solicitud de liquidación {$request->code} — {$agency->name}",
                "{$commissions->count()} comisión(es) por PEN {$request->amount_pen}", ['client_id' => $agency->client_id, 'related_type' => 'b2b_settlement', 'related_id' => $request->id]);

            return $request;
        });
    }

    private function notifyReservation(B2bAgency $agency, Reservation $reservation, string $status, int $vouchers): void
    {
        $data = ['client_id' => $reservation->client_id, 'related_type' => 'reservation', 'related_id' => $reservation->id];
        $email = $agency->booking_email ?: $agency->client?->email;
        if ($email && $status === Reservation::CONFIRMED) {
            $this->notify->push('reserva.confirmada', 'email', $email, "Reserva {$reservation->code} confirmada", "{$reservation->title} · {$reservation->travel_from->toDateString()}".($vouchers ? ' · voucher emitido' : ''), $data);
        }
        foreach (CatalogRecipients::managers() as $m) {
            $this->notify->push('reserva.b2b', 'portal', $m, "Nueva reserva B2B {$reservation->code} — {$agency->name}", Reservation::STATUS_LABELS[$status]." · {$reservation->currency} {$reservation->total_amount}", $data);
        }
    }
}
