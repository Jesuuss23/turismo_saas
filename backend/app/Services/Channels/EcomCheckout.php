<?php

namespace App\Services\Channels;

use App\Exceptions\ApiException;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Departure;
use App\Models\Catalog\Product;
use App\Models\Channel\EcomOrder;
use App\Models\Channel\EcomPassenger;
use App\Models\Channel\EcomPaymentAttempt;
use App\Models\Crm\Client;
use App\Models\Finance\Payment;
use App\Models\Finance\Receivable;
use App\Models\Iam\User;
use App\Models\Ops\Voucher;
use App\Services\AuditLogger;
use App\Services\Bookings\BookingInventory;
use App\Services\Catalog\AvailabilityService;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Crm\ClientService;
use App\Services\Crm\CrmClock;
use App\Services\Finance\FxRates;
use App\Services\Finance\PaymentService;
use App\Services\Notify;
use App\Services\SequenceService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Checkout web (spec 08 §4.2, PROC-054) consumido por el sitio público (espec. 15): catálogo publicado, disponibilidad,
 * pedido con hold, intentos de pago con la pasarela (sandbox hasta definir INT-02) y confirmación idempotente por `gateway_txn_id`.
 */
class EcomCheckout
{
    public const EMAIL = '/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i';

    public function __construct(
        private readonly EcomPricing $pricing,
        private readonly ChannelReservations $builder,
        private readonly AvailabilityService $availability,
        private readonly PaymentService $payments,
        private readonly ClientService $clients,
        private readonly CrmClock $clock,
        private readonly FxRates $fx,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
        private readonly TenantContext $tenant,
        private readonly SandboxGateway $gateway,
    ) {}

    /** ECO-BR-02: ACTIVE ∧ is_ecommerce ∧ tarifa vigente. */
    public function published(): Collection
    {
        $today = $this->clock->today()->toDateString();

        return Product::where('status', Product::ACTIVE)->where('is_ecommerce', true)->with(['primaryDestination', 'category', 'media'])->orderByDesc('is_featured')->orderBy('name')->get()
            ->filter(fn (Product $p) => $this->pricing->rates($p, $today)[0] !== null)->values();
    }

    public function findPublished(string $slugOrRef): Product
    {
        $p = Product::where('status', Product::ACTIVE)->where(fn ($q) => $q->where('slug', $slugOrRef)->orWhere('ref', $slugOrRef))->first();
        if (! $p || ! $p->is_ecommerce || ! $this->pricing->rates($p, $this->clock->today()->toDateString())[0]) {
            throw new ApiException(404, 'ECO-BR-02', 'ECO-BR-02', 'Este producto no está disponible para la venta en línea.');
        }

        return $p;
    }

    public function owner(): User
    {
        $id = data_get($this->tenant->tenant()?->settings, 'channels.web_default_owner_id');

        return ($id ? User::find($id) : null) ?? User::where('code', 'U03')->first() ?? CatalogRecipients::sales()->first() ?? CatalogRecipients::managers()->firstOrFail();
    }

    /**
     * ECO-BR-06/07: pax, salida o fecha, anticipación y cupos.
     *
     * @return array{ok: bool, message: string, departure: ?Departure, date: ?string, on_request: bool}
     */
    public function availability(Product $product, array $data): array
    {
        $adults = (int) ($data['adults'] ?? 0);
        $children = (int) ($data['children'] ?? 0);
        $pax = $adults + $children;
        $fail = fn (string $m) => ['ok' => false, 'message' => $m, 'departure' => null, 'date' => null, 'on_request' => false];
        if ($adults < 1) {
            return $fail('Debe haber al menos un adulto.');
        }
        if ($pax > $product->maximum_passengers) {
            return $fail("Máximo {$product->maximum_passengers} pasajeros por compra.");
        }
        if ($pax < $product->minimum_passengers) {
            return $fail("Mínimo {$product->minimum_passengers} pasajeros por compra.");
        }
        $today = $this->clock->today();
        $min = $today->copy()->addDays($product->booking_advance_min_days)->toDateString();
        if (BookingInventory::bySalida($product)) {
            $d = Departure::where('product_id', $product->id)->find($data['departure_id'] ?? null);
            if (! $d) {
                return $fail('Elija una salida.');
            }
            if (! in_array($d->status, ['abierta'], true) || $d->departure_date->toDateString() < $today->toDateString()) {
                return $fail("La salida {$d->ref} está agotada o cerrada.");
            }
            if ($d->departure_date->toDateString() < $min) {
                return $fail('Venta cerrada para esa salida (anticipación mínima).');
            }
            $free = $this->availability->available($d);
            if ($free < $pax) {
                return $fail("Sin disponibilidad: quedan {$free} cupo(s) en la salida del ".$d->departure_date->format('d/m/Y').' (RT-4).');
            }

            return ['ok' => true, 'message' => "{$free} cupo(s) disponibles.", 'departure' => $d, 'date' => $d->departure_date->toDateString(), 'on_request' => false];
        }
        $date = (string) ($data['date'] ?? '');
        $max = $today->copy()->addDays($product->booking_advance_max_days)->toDateString();
        if ($date === '' || $date < $min || $date > $max) {
            return $fail("Elija una fecha entre {$min} y {$max}.");
        }

        return ['ok' => true, 'message' => 'Producto bajo solicitud: confirmación del proveedor en máximo 24 h; el cobro se realiza ahora y se reembolsa si no hay confirmación.',
            'departure' => null, 'date' => $date, 'on_request' => true];
    }

    /** Precio público (POST /public/shop/price). */
    public function price(Product $product, array $data): array
    {
        $date = (string) ($data['date'] ?? '');
        if (! empty($data['departure_id'])) {
            $date = (string) Departure::where('product_id', $product->id)->whereKey($data['departure_id'])->value('departure_date')?->toDateString();
        }
        $date = $date !== '' ? $date : $this->clock->today()->toDateString();
        $quote = $this->pricing->quote($product, $date, $data);
        [, $child] = $this->pricing->rates($product, $date);

        return $quote + ['children_allowed' => $this->pricing->childrenAllowed($product, $child), 'date' => $date];
    }

    /**
     * ECO-BR-08/09/10: crea pedido, cliente (por email), reserva `pendiente_pago`, CxC que vence hoy y hold de cupos.
     *
     * @return array{order: EcomOrder, token: string}
     */
    public function createOrder(array $data): array
    {
        $product = $this->findPublished((string) ($data['product'] ?? $data['product_id'] ?? ''));
        $buyer = $this->buyer($data['buyer'] ?? []);
        $adults = (int) ($data['adults'] ?? 0);
        $children = (int) ($data['children'] ?? 0);
        $companions = array_values((array) ($data['passengers'] ?? []));
        foreach ($companions as $i => $c) {
            if (trim((string) ($c['full_name'] ?? '')) === '' || trim((string) ($c['doc_number'] ?? '')) === '') {
                throw ApiException::invalid("passengers.{$i}.full_name", 'Complete nombre y documento de cada acompañante (ECO-BR-08).');
            }
        }
        if (count($companions) !== $adults + $children - 1) {
            throw ApiException::invalid('passengers', 'Indique nombre y documento de cada acompañante (ECO-BR-08).');
        }
        $check = $this->availability($product, $data);
        if (! $check['ok']) {
            throw ApiException::rule('ECO-BR-09', $check['message'], ['step' => 'disponibilidad']);
        }
        $quote = $this->pricing->quote($product, $check['date'], $data);
        if ($quote['promo_state'] === 'bad') {
            throw ApiException::unprocessable('ECO-BR-05', 'Código no válido para este producto o fuera de vigencia.');
        }
        $owner = $this->owner();
        $token = Str::random(48);
        $product->loadMissing(['policies', 'items.service', 'itinerary.days.items', 'currentVersion']);

        return $this->availability->lockMany($check['departure'] ? [$check['departure']->id] : [], function (Collection $locked) use ($product, $buyer, $adults, $children, $companions, $check, $quote, $owner, $token, $data) {
            $departure = $check['departure'] ? $locked[$check['departure']->id] : null;
            if ($departure && $this->availability->available($departure) < $adults + $children) {
                throw ApiException::rule('ECO-BR-09', 'La salida se agotó mientras completaba sus datos. Elija otra salida.', ['step' => 'disponibilidad']);
            }
            $client = $this->client($buyer, $owner);
            $now = now();
            $order = EcomOrder::create([
                'code' => $this->sequences->next('ecom_order'), 'ordered_at' => $now, 'client_id' => $client->id, 'customer_name' => $buyer['name'],
                'customer_email' => $buyer['email'], 'customer_phone' => $buyer['phone'], 'customer_country' => $buyer['country'], 'product_id' => $product->id,
                'departure_id' => $departure?->id, 'service_date' => $check['date'], 'pax_adults' => $adults, 'pax_children' => $children,
                'config_snapshot' => array_map(fn ($o) => ['group' => $o['group'], 'option' => $o['option'], 'delta_pp' => $o['delta_pp']], $quote['options']),
                'price_snapshot' => array_intersect_key($quote, array_flip(['rate_id', 'channel', 'adult_unit', 'child_unit', 'delta_pp', 'subtotal', 'discount', 'discount_label', 'total'])),
                'promo_code' => $quote['promo_code'], 'promotion_id' => $quote['promotion_id'], 'discount_amount' => $quote['discount'], 'total' => $quote['total'],
                'currency' => $quote['currency'], 'cost_total' => $quote['cost'], 'status' => 'pago_pendiente',
                'hold_expires_at' => $now->copy()->addMinutes($this->availability->holdMinutes()), 'buyer_snapshot' => $buyer,
                'utm' => isset($data['utm']) && is_array($data['utm']) ? array_slice($data['utm'], 0, 10) : null, 'access_token_hash' => hash('sha256', $token),
            ]);
            $pax = [['name' => $buyer['name'], 'doc_type' => $buyer['doc_type_booking'], 'doc' => $buyer['doc_number'], 'type' => 'ADT', 'email' => $buyer['email'], 'phone' => $buyer['phone']]];
            EcomPassenger::create(['order_id' => $order->id, 'position' => 1, 'full_name' => $buyer['name'], 'doc_type' => $buyer['doc_type'], 'doc_number' => $buyer['doc_number'], 'pax_type' => 'ADT']);
            foreach ($companions as $i => $c) {
                $type = $i + 1 < $adults ? 'ADT' : 'CHD';
                EcomPassenger::create(['order_id' => $order->id, 'position' => $i + 2, 'full_name' => trim($c['full_name']), 'doc_number' => trim($c['doc_number']), 'pax_type' => $type]);
                $pax[] = ['name' => trim($c['full_name']), 'doc' => trim($c['doc_number']), 'type' => $type];
            }
            [$reservation, $receivable] = $this->builder->create([
                'product' => $product, 'departure' => $departure, 'date' => $check['date'], 'adt' => $adults, 'chd' => $children, 'client_id' => $client->id, 'owner' => $owner,
                'channel' => 'E-commerce', 'status' => Reservation::PENDING_PAYMENT, 'currency' => $quote['currency'], 'total' => $quote['total'], 'cost' => $quote['cost'],
                'title' => "{$product->name} — {$buyer['name']} (web)", 'ecom_order_id' => $order->id, 'passengers' => $pax,
                'receivable' => ['concept' => "Pedido web {$order->code} — {$product->name}", 'due' => $this->clock->today()->toDateString(), 'kind' => 'saldo'],
                'audit_action' => 'crear_ecommerce', 'origin' => "pedido web {$order->code}",
            ], $owner);
            $order->forceFill(['reservation_id' => $reservation->id, 'receivable_id' => $receivable->id])->save();
            $this->audit->log('Pedido web', $order->code, 'crear', null, "Pago pendiente · {$product->ref} · {$order->currency} {$order->total} · {$buyer['email']}".($order->promo_code ? " · {$order->promo_code}" : ''), system: true);

            return ['order' => $order->fresh(), 'token' => $token];
        });
    }

    /** Pedido por código y token del comprador (o null si no coincide). */
    public function orderFor(string $code, ?string $token): EcomOrder
    {
        $order = EcomOrder::where('code', $code)->first();
        if (! $order || ! $token || ! hash_equals($order->access_token_hash, hash('sha256', $token))) {
            throw ApiException::notFound();
        }

        return $order;
    }

    /**
     * Intento de pago con la pasarela sandbox (ECO-BR-12/13/16): 4111… y 5555… aprueban; 4000…0002 rechaza (51). Solo se guardan los últimos 4.
     */
    public function pay(EcomOrder $order, array $card): EcomPaymentAttempt
    {
        return $this->process($order, $this->gateway->charge($card));
    }

    /** Webhook / respuesta de la pasarela. Idempotente por `gateway_txn_id` (T14). */
    public function process(EcomOrder $order, array $r): EcomPaymentAttempt
    {
        return DB::transaction(function () use ($order, $r) {
            if ($existing = EcomPaymentAttempt::where('gateway_txn_id', $r['txn_id'])->first()) {
                return $existing;
            }
            $order = EcomOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $order->isOpen()) {
                throw ApiException::rule('ECO-BR-12', "El pedido {$order->code} está ".mb_strtolower(EcomOrder::STATUS_LABELS[$order->status]).': no admite pagos.');
            }
            $owner = $this->owner();
            $attempt = EcomPaymentAttempt::create(['order_id' => $order->id, 'attempted_at' => now(), 'card_masked' => '•••• '.$r['last4'], 'result' => $r['result'],
                'gateway_code' => $r['code'] ?? null, 'message' => $r['message'] ?? null, 'gateway_txn_id' => $r['txn_id']]);
            $receivable = Receivable::findOrFail($order->receivable_id);
            if ($r['result'] === 'rechazado') {
                $payment = Payment::create(['code' => $this->sequences->next('payment'), 'receivable_id' => $receivable->id, 'reservation_id' => $order->reservation_id,
                    'client_id' => $order->client_id, 'kind' => 'cobro', 'source' => 'ecommerce', 'method' => 'Tarjeta (e-commerce)', 'amount' => $order->total,
                    'currency' => $order->currency, 'fx_rate' => $order->currency === 'PEN' ? null : $this->fx->rate($order->currency), 'paid_on' => $this->clock->today()->toDateString(),
                    'reference' => "{$r['txn_id']} · {$attempt->card_masked}", 'status' => 'rechazado', 'reject_reason' => $r['message'] ?? 'Rechazado por la pasarela']);
                $attempt->forceFill(['payment_id' => $payment->id])->save();
                $order->forceFill(['status' => 'pago_rechazado'])->save();
                $this->audit->log('Pedido web', $order->code, 'pago_rechazado', 'Pago pendiente', "Pago rechazado · {$attempt->card_masked} · {$attempt->message}", system: true);
                $this->audit->log('Pago', $payment->code, 'pago_rechazado', null, "{$order->code} · {$attempt->message}", system: true);
                $this->notify->push('pago.rechazado', 'email', $order->customer_email, "Pago rechazado — pedido {$order->code}",
                    "{$attempt->message}. Sus cupos siguen reservados hasta ".$order->hold_expires_at?->timezone($this->clock->timezone())->format('H:i').'; puede reintentar con otra tarjeta.',
                    ['client_id' => $order->client_id, 'related_type' => 'ecom_order', 'related_id' => $order->id]);

                return $attempt->fresh();
            }
            $payment = $this->payments->register($receivable, ['method' => 'Tarjeta (e-commerce)', 'amount' => (string) $order->total,
                'reference' => "{$r['txn_id']} · {$attempt->card_masked}", 'note' => "Pedido web {$order->code} · {$attempt->message}"], $owner, 'ecommerce');
            $attempt->forceFill(['payment_id' => $payment->id])->save();
            $reservation = $this->builder->confirmPaid(Reservation::findOrFail($order->reservation_id), $owner);
            $vouchers = $reservation->status === Reservation::CONFIRMED ? $this->builder->issueVouchers($reservation, $owner, 'ecommerce') : [];
            $before = EcomOrder::STATUS_LABELS[$order->status];
            $order->forceFill(['status' => 'confirmado', 'hold_expires_at' => null])->save();
            $this->audit->log('Pedido web', $order->code, 'cambio_estado', $before,
                "Confirmado · {$attempt->card_masked} · {$attempt->message} · {$reservation->code}", system: true);
            $data = ['client_id' => $order->client_id, 'related_type' => 'ecom_order', 'related_id' => $order->id];
            $this->notify->push('pago.recibido', 'email', $order->customer_email, "Pago recibido — pedido {$order->code}", "{$order->currency} {$order->total} · {$attempt->card_masked}", $data);
            $this->notify->push('reserva.confirmada', 'email', $order->customer_email, $reservation->status === Reservation::CONFIRMED
                ? "Su reserva {$reservation->code} está confirmada" : "Recibimos su pedido {$order->code}: confirmación del proveedor en máximo 24 h", $reservation->title, $data);

            return $attempt->fresh();
        });
    }

    /** Estado del pedido para el comprador (GET /public/shop/orders/{code}). */
    public function present(EcomOrder $order): array
    {
        $order->loadMissing(['product', 'departure', 'reservation', 'passengers', 'attempts']);
        $vouchers = $order->reservation_id ? Voucher::where('reservation_id', $order->reservation_id)->where('status', '<>', Voucher::VOIDED)->get() : collect();

        return [
            'code' => $order->code, 'status' => $order->status, 'status_label' => EcomOrder::STATUS_LABELS[$order->status], 'product' => $order->product?->name,
            'service_date' => $order->service_date->toDateString(), 'departure' => $order->departure ? ['ref' => $order->departure->ref, 'time' => substr((string) $order->departure->departure_time, 0, 5),
                'meeting_point' => $order->departure->meeting_point] : null,
            'adults' => $order->pax_adults, 'children' => $order->pax_children, 'price' => $order->price_snapshot, 'options' => $order->config_snapshot,
            'promo_code' => $order->promo_code, 'total' => (string) $order->total, 'currency' => $order->currency, 'hold_expires_at' => $order->hold_expires_at?->toIso8601String(),
            'reservation' => $order->reservation ? ['code' => $order->reservation->code, 'status' => $order->reservation->status,
                'status_label' => Reservation::STATUS_LABELS[$order->reservation->status]] : null,
            'vouchers' => $vouchers->map(fn (Voucher $v) => ['code' => $v->code, 'service' => $v->service_label, 'version' => $v->version])->values()->all(),
            'passengers' => $order->passengers->map(fn (EcomPassenger $p) => ['name' => $p->full_name, 'type' => $p->pax_type])->all(),
            'attempts' => $order->attempts->map(fn (EcomPaymentAttempt $a) => ['at' => $a->attempted_at->toIso8601String(), 'card' => $a->card_masked, 'result' => $a->result, 'message' => $a->message])->all(),
        ];
    }

    /** ECO-BR-08. */
    private function buyer(array $b): array
    {
        $b = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $b);
        foreach (['name' => 'su nombre', 'doc_type' => 'el tipo de documento', 'doc_number' => 'el número de documento', 'phone' => 'su celular', 'country' => 'su país'] as $f => $label) {
            if (($b[$f] ?? '') === '') {
                throw ApiException::invalid("buyer.{$f}", "Indique {$label}.");
            }
        }
        if (! preg_match(self::EMAIL, (string) ($b['email'] ?? ''))) {
            throw ApiException::invalid('buyer.email', 'Ingrese un correo válido.');
        }
        if (! in_array($b['doc_type'], ['DNI', 'Pasaporte', 'Carné de extranjería'], true)) {
            throw ApiException::invalid('buyer.doc_type', 'Tipo de documento inválido.');
        }
        if (empty($b['accept_privacy'])) {
            throw ApiException::invalid('buyer.accept_privacy', 'Debe aceptar el tratamiento de datos personales (Ley 29733).');
        }

        return ['name' => mb_substr($b['name'], 0, 150), 'doc_type' => $b['doc_type'], 'doc_number' => mb_substr($b['doc_number'], 0, 30), 'email' => mb_strtolower($b['email']),
            'phone' => mb_substr($b['phone'], 0, 40), 'country' => mb_substr($b['country'], 0, 60),
            'doc_type_booking' => match ($b['doc_type']) {
                'DNI' => 'DNI', 'Pasaporte' => 'Pasaporte', default => 'CE'
            }];
    }

    /** §11-12: cliente CRM por email (reutiliza; si no, Persona Retail con tags Web/E-commerce). */
    private function client(array $buyer, User $owner): Client
    {
        $client = Client::where('email_norm', $buyer['email'])->orderBy('created_at')->first();
        if ($client) {
            return $client;
        }
        $docType = match ($buyer['doc_type']) {
            'DNI' => 'DNI', 'Pasaporte' => 'PASAPORTE', default => 'CE'
        };
        $client = Client::where('doc_number', $buyer['doc_number'])->first()
            ?? $this->clients->create(['kind' => 'persona', 'name' => $buyer['name'], 'doc_type' => $docType, 'doc_number' => $buyer['doc_number'], 'email' => $buyer['email'],
                'phone' => $buyer['phone'], 'country' => $buyer['country'], 'segment_code' => 'retail', 'tags' => ['Web', 'E-commerce'], 'owner_id' => $owner->id], $owner, true);
        $this->audit->log('Cliente', $client->code, 'crear_desde_ecommerce', null, $buyer['email'], system: true);

        return $client;
    }
}
