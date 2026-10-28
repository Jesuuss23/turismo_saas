<?php

namespace App\Services\Site;

use App\Events\Bookings\ReservationCreated;
use App\Exceptions\ApiException;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\Passenger;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Hold;
use App\Models\Catalog\Product;
use App\Models\Catalog\Provider;
use App\Models\Channel\EcomOrder;
use App\Models\Channel\EcomOrderLine;
use App\Models\Channel\EcomPassenger;
use App\Models\Channel\EcomPaymentAttempt;
use App\Models\Channel\WebCart;
use App\Models\Crm\Client;
use App\Models\Finance\Payment;
use App\Models\Finance\Receivable;
use App\Models\Iam\User;
use App\Models\Ops\Voucher;
use App\Services\AuditLogger;
use App\Services\Bookings\ReceivableService;
use App\Services\Bookings\ReservationService;
use App\Services\Bookings\ServiceBreakdown;
use App\Services\Catalog\AvailabilityService;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Channels\ChannelReservations;
use App\Services\Channels\EcomCheckout;
use App\Services\Channels\SandboxGateway;
use App\Services\Crm\ClientService;
use App\Services\Crm\CrmClock;
use App\Services\Finance\Fin;
use App\Services\Finance\PaymentService;
use App\Services\Marketing\ConsentService;
use App\Services\Notify;
use App\Services\SequenceService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Checkout del sitio (spec 15 §4.2, SIT-BR-07/09/13–20): pedido multilínea (una reserva por línea, v2.1 §3.14) con anticipo o pago total,
 * tarjeta sandbox o transferencia/Yape/Plin por validar, consumo de cupos al cobrar y vouchers solo con todas las CxC pagadas (RT-11).
 */
class SiteCheckout
{
    public const POLICY_VERSION = 'PDP-2026.1';

    public const DOC_TYPES = ['DNI' => 'DNI', 'CE' => 'Carné de extranjería', 'Pasaporte' => 'Pasaporte'];

    public const OFFLINE_METHODS = ['transferencia' => 'Transferencia BCP', 'yape' => 'Yape', 'plin' => 'Plin'];

    public const CARD_METHOD = 'Tarjeta (e-commerce)';

    public function __construct(
        private readonly CartService $carts,
        private readonly SitePricing $pricing,
        private readonly EcomCheckout $ecom,
        private readonly ChannelReservations $builder,
        private readonly ReservationService $reservations,
        private readonly ReceivableService $receivables,
        private readonly AvailabilityService $availability,
        private readonly PaymentService $payments,
        private readonly ClientService $clients,
        private readonly SandboxGateway $gateway,
        private readonly SequenceService $sequences,
        private readonly CrmClock $clock,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /** POST /public/checkout/validate: valida un paso sin crear nada (422 por campo). */
    public function validate(WebCart $cart, string $step, array $data): array
    {
        $errors = match ($step) {
            'comprador' => $this->buyerErrors((array) ($data['buyer'] ?? []), (array) ($data['billing'] ?? [])),
            'pasajeros' => $this->passengerErrors($cart, (array) ($data['passengers'] ?? [])),
            'revision' => $this->consentErrors((array) ($data['consents'] ?? [])),
            default => throw ApiException::invalid('step', 'Paso inválido.'),
        };
        self::throwIf($errors);

        return ['ok' => true, 'step' => $step];
    }

    /**
     * POST /public/checkout/orders: cliente, pedido, líneas, pasajeros, una reserva `pendiente_pago` por línea con sus servicios y CxC (total o
     * anticipo + saldo), en una transacción. Revalida holds (SIT-BR-07) y la modalidad (SIT-BR-09). @return array{order: EcomOrder, token: string}
     */
    public function createOrder(WebCart $cart, array $data, ?User $session = null): array
    {
        $cart->load(['items.product.type', 'items.product.policies', 'items.product.currentVersion', 'items.departure', 'items.hold']);
        if ($cart->items->isEmpty()) {
            throw ApiException::unprocessable('SIT-BR-07', 'Su carrito está vacío.');
        }
        $buyer = (array) ($data['buyer'] ?? []);
        $billing = (array) ($data['billing'] ?? ['type' => 'Boleta']);
        self::throwIf($this->buyerErrors($buyer, $billing) + $this->passengerErrors($cart, (array) ($data['passengers'] ?? [])) + $this->consentErrors((array) ($data['consents'] ?? [])));
        $first = $cart->items->min(fn ($i) => $i->service_date->toDateString());
        $mode = ($data['pay_mode'] ?? 'total') === 'anticipo' ? 'anticipo' : 'total';
        $days = (int) $this->clock->today()->diffInDays(Carbon::parse($first), false);
        $pct = $mode === 'anticipo' ? CartService::depositPct($days) : 100;
        if ($pct === null) {
            throw ApiException::unprocessable('SIT-BR-09', 'Anticipo no disponible: su viaje inicia en menos de 15 días. Elija pago total.');
        }
        foreach ($cart->items as $item) {
            $this->carts->ensureHold($cart, $item);
        }
        $cart->load('items.hold');
        $currency = $cart->currency;
        $fx = $this->pricing->usdRate();
        $quotes = $cart->items->mapWithKeys(fn ($item) => [$item->id => $this->carts->quote($item, $cart->promo_code)]);
        $owner = $this->ecom->owner();
        $token = Str::random(48);
        $consents = (array) $data['consents'];
        $now = now();
        $balanceDue = $mode === 'anticipo' ? Carbon::parse($first)->subDays(15)->max($this->clock->today())->toDateString() : null;

        return DB::transaction(function () use ($cart, $buyer, $billing, $data, $mode, $pct, $currency, $fx, $quotes, $owner, $token, $consents, $now, $balanceDue, $session) {
            $cart = WebCart::whereKey($cart->id)->lockForUpdate()->firstOrFail();
            if ($cart->status !== 'activo' || $cart->order_id) {
                throw ApiException::rule('SIT-BR-07', 'Este carrito ya generó un pedido.');
            }
            $cart->load(['items.product.type', 'items.product.policies', 'items.product.currentVersion', 'items.product.items.service', 'items.product.itinerary.days.items', 'items.departure', 'items.hold']);
            $client = $this->client($buyer, $billing, $owner, $session);
            $conv = fn (string $v, string $from) => $this->pricing->convert($v, $from, $currency, $fx);
            $total = BigDecimal::zero();
            $discount = BigDecimal::zero();
            $cost = BigDecimal::zero();
            foreach ($cart->items as $item) {
                $q = $quotes[$item->id];
                $total = $total->plus($conv($q['total'], $q['currency']));
                $discount = $discount->plus($conv($q['discount'], $q['currency']));
                $cost = $cost->plus($conv($q['cost'], $q['currency']));
            }
            $firstItem = $cart->items->sortBy(fn ($i) => $i->service_date->toDateString())->first();
            $dueNow = $mode === 'anticipo' ? $total->multipliedBy($pct)->dividedBy(100, 2, RoundingMode::HalfUp) : $total->toScale(2);
            $holdUntil = $cart->items->pluck('hold.expires_at')->filter()->min() ?? $now->copy()->addMinutes($this->availability->holdMinutes());
            $order = EcomOrder::create([
                'code' => $this->sequences->next('ecom_order'), 'ordered_at' => $now, 'client_id' => $client->id, 'customer_name' => $buyer['name'],
                'customer_email' => mb_strtolower($buyer['email']), 'customer_phone' => $buyer['phone'], 'customer_country' => $buyer['country'],
                'product_id' => $firstItem->product_id, 'departure_id' => $firstItem->departure_id, 'service_date' => $firstItem->service_date->toDateString(),
                'pax_adults' => $cart->items->max('pax_adults'), 'pax_children' => $cart->items->max('pax_children'), 'pax_infants' => $cart->items->max('pax_infants'),
                'config_snapshot' => [], 'price_snapshot' => ['lines' => $cart->items->count(), 'currency' => $currency, 'fx_rate' => $fx, 'total' => (string) $total->toScale(2)],
                'promo_code' => $cart->promo_code, 'discount_amount' => (string) $discount->toScale(2), 'total' => (string) $total->toScale(2), 'currency' => $currency,
                'cost_total' => (string) $cost->toScale(2), 'status' => 'pago_pendiente', 'hold_expires_at' => $holdUntil,
                'buyer_snapshot' => array_intersect_key($buyer, array_flip(['name', 'email', 'phone', 'country', 'doc_type', 'doc_number'])),
                'utm' => $cart->utm, 'access_token_hash' => hash('sha256', $token), 'cart_id' => $cart->id, 'sales_channel' => 'sitio_web', 'locale' => $cart->locale,
                'fx_rate' => $fx, 'pay_mode' => $mode, 'deposit_pct' => $pct, 'amount_due_now' => (string) $dueNow, 'balance_due_date' => $balanceDue,
                'billing' => self::billingOf($billing, $buyer), 'consents' => ['terms_at' => $now->toIso8601String(), 'cancellation_at' => $now->toIso8601String(),
                    'privacy_at' => $now->toIso8601String(), 'marketing_at' => ! empty($consents['marketing']) ? $now->toIso8601String() : null, 'policy_version' => self::POLICY_VERSION],
            ]);
            $passengers = $this->storePassengers($order, (array) $data['passengers'], $buyer);
            foreach ($cart->items as $item) {
                $this->createLine($order, $item, $quotes[$item->id], $client, $owner, $passengers, $mode, $pct, $balanceDue, $fx);
            }
            $cart->forceFill(['order_id' => $order->id, 'email' => $order->customer_email, 'crm_client_id' => $client->id, 'last_activity_at' => $now])->save();
            $this->audit->log('Pedido web', $order->code, 'crear', null, "Pago pendiente · {$cart->items->count()} línea(s) · {$currency} {$order->total} · ".($mode === 'anticipo' ? "anticipo {$pct} %" : 'pago total')
                ." · {$order->customer_email}".($order->promo_code ? " · {$order->promo_code}" : ''), system: true);
            if (! empty($consents['marketing']) && $client->email) {
                $this->grantMarketing($client, "Checkout {$order->code}");
            }

            return ['order' => $order->fresh(), 'token' => $token];
        });
    }

    /**
     * POST /public/orders/{code}/payments (§4.2): tarjeta (sandbox) o transferencia/Yape/Plin por validar. Idempotente por `Idempotency-Key`.
     */
    public function pay(EcomOrder $order, array $data, ?string $idemKey = null): array
    {
        if ($idemKey && ($done = EcomPaymentAttempt::where('idempotency_key', $idemKey)->first())) {
            return $this->attemptResult($done, $order->fresh());
        }
        if (! $order->isOpen()) {
            throw ApiException::rule('SIT-BR-17', "El pedido {$order->code} está ".mb_strtolower(EcomOrder::STATUS_LABELS[$order->status]).': no admite pagos.');
        }
        $method = (string) ($data['method'] ?? 'card');
        $cart = $order->cart_id ? WebCart::find($order->cart_id) : null;
        $lines = $order->lines()->with(['departure', 'reservation'])->get();
        // SIT-BR-07: revalidar cupos antes de cobrar.
        foreach ($lines as $line) {
            $this->ensureLineHold($order, $line, $cart);
        }
        if (in_array($method, ['transfer', 'transferencia', 'yape', 'plin'], true)) {
            return $this->payOffline($order->fresh(), $lines, $method === 'transfer' ? 'transferencia' : $method, trim((string) ($data['operation_ref'] ?? '')), $idemKey);
        }
        $charge = $this->gateway->charge((array) ($data['card'] ?? $data));

        return $this->attemptResult($this->applyCharge($order, $lines, $charge, $idemKey, $cart), $order->fresh());
    }

    /** Webhook de la pasarela para un pedido del sitio: idempotente por `gateway_txn_id` (§10-11). */
    public function processWebhook(EcomOrder $order, array $r): EcomPaymentAttempt
    {
        if ($existing = EcomPaymentAttempt::where('gateway_txn_id', $r['txn_id'])->first()) {
            return $existing;
        }
        $cart = $order->cart_id ? WebCart::find($order->cart_id) : null;
        $lines = $order->lines()->with(['departure', 'reservation'])->get();

        return $this->applyCharge($order, $lines, ['txn_id' => $r['txn_id'], 'result' => $r['result'], 'last4' => $r['last4'], 'code' => $r['code'] ?? null,
            'message' => $r['message'] ?? ($r['result'] === 'aprobado' ? 'Aprobado' : 'Rechazado por la pasarela')], null, $cart);
    }

    private function applyCharge(EcomOrder $order, $lines, array $charge, ?string $idemKey, ?WebCart $cart): EcomPaymentAttempt
    {
        $amount = (string) $order->amount_due_now;
        $masked = "•••• {$charge['last4']}";

        return DB::transaction(function () use ($order, $lines, $charge, $amount, $masked, $idemKey, $cart) {
            $order = EcomOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $order->isOpen()) {
                throw ApiException::rule('SIT-BR-17', "El pedido {$order->code} ya no admite pagos.");
            }
            $owner = $this->ecom->owner();
            $attempt = EcomPaymentAttempt::create(['order_id' => $order->id, 'attempted_at' => now(), 'card_masked' => $masked, 'result' => $charge['result'],
                'gateway_code' => $charge['code'], 'message' => $charge['message'], 'gateway_txn_id' => $charge['txn_id'], 'method' => 'tarjeta', 'amount' => $amount,
                'currency' => $order->currency, 'idempotency_key' => $idemKey]);
            if ($charge['result'] === 'rechazado') {
                // SIT-BR-16: el rechazo no libera cupos ni anula la obligación; se puede reintentar.
                $rcv = $this->dueNow($lines)->first();
                $payment = Payment::create(['code' => $this->sequences->next('payment'), 'receivable_id' => $rcv?->id, 'reservation_id' => $rcv?->reservation_id,
                    'client_id' => $order->client_id, 'kind' => 'cobro', 'source' => 'ecommerce', 'method' => self::CARD_METHOD, 'amount' => $amount, 'currency' => $order->currency,
                    'fx_rate' => $order->currency === 'PEN' ? null : $order->fx_rate, 'paid_on' => $this->clock->today()->toDateString(),
                    'reference' => "{$charge['txn_id']} · {$masked}", 'status' => 'rechazado', 'reject_reason' => $charge['message']]);
                $attempt->forceFill(['payment_id' => $payment->id])->save();
                $before = EcomOrder::STATUS_LABELS[$order->status];
                $order->forceFill(['status' => 'pago_rechazado'])->save();
                $this->audit->log('Pedido web', $order->code, 'pago_rechazado', $before, "Pago rechazado · {$masked} · {$charge['message']}", system: true);
                $this->audit->log('Pago', $payment->code, 'pago_rechazado', null, "{$order->code} · {$charge['message']}", system: true);
                $this->notify->push('pago.rechazado', 'email', $order->customer_email, "Pago rechazado — pedido {$order->code}",
                    "{$charge['message']}. Sus cupos siguen reservados hasta ".$order->hold_expires_at?->timezone($this->clock->timezone())->format('H:i').'; puede reintentar con otra tarjeta.', $this->ref($order));

                return $attempt;
            }
            $first = null;
            foreach ($this->dueNow($lines) as $rcv) {
                $payment = $this->payments->register($rcv, ['method' => self::CARD_METHOD, 'amount' => $rcv->balance(), 'reference' => "{$charge['txn_id']} · {$masked}",
                    'note' => "Pedido web {$order->code} · {$charge['message']}"], $owner, 'ecommerce');
                $first ??= $payment;
            }
            $attempt->forceFill(['payment_id' => $first?->id])->save();
            $this->consumeHolds($order, $lines, $owner);
            $this->confirm($order, $lines, $owner, "Confirmado · {$masked} · {$charge['message']}");
            $cart?->forceFill(['status' => 'convertido', 'last_activity_at' => now()])->save();
            $this->notify->push('pago.recibido', 'email', $order->customer_email, "Pago recibido — pedido {$order->code}", "{$order->currency} {$amount} · {$masked}", $this->ref($order));

            return $attempt;
        });
    }

    /** SIT-BR-17: pago `pendiente` por validar; los cupos se consumen y la reserva queda `pendiente_pago`. */
    private function payOffline(EcomOrder $order, $lines, string $method, string $ref, ?string $idemKey): array
    {
        if ($ref === '' || mb_strlen($ref) > 60) {
            throw ApiException::invalid('operation_ref', $method === 'transferencia' ? 'Indique el número de operación de la transferencia.' : 'Indique el código de operación de '.ucfirst($method).'.');
        }
        $attempt = DB::transaction(function () use ($order, $lines, $method, $ref, $idemKey) {
            $order = EcomOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $owner = $this->ecom->owner();
            $first = null;
            foreach ($this->dueNow($lines) as $rcv) {
                $payment = Payment::create(['code' => $this->sequences->next('payment'), 'receivable_id' => $rcv->id, 'reservation_id' => $rcv->reservation_id,
                    'client_id' => $order->client_id, 'kind' => 'cobro', 'source' => 'ecommerce', 'method' => self::OFFLINE_METHODS[$method], 'amount' => $rcv->balance(),
                    'currency' => $rcv->currency, 'fx_rate' => $rcv->currency === 'PEN' ? null : $order->fx_rate, 'paid_on' => $this->clock->today()->toDateString(),
                    'reference' => $ref, 'note' => "Pedido web {$order->code} · por validar", 'status' => 'pendiente']);
                Fin::history('payment', $payment->id, 'pendiente', 'Registrado desde el sitio web (por validar)', $owner);
                $this->audit->log('Pago', $payment->code, 'registrar', null, "Por validar · {$payment->method} · {$payment->currency} {$payment->amount} · {$ref} · {$order->code}", system: true);
                $first ??= $payment;
            }
            $attempt = EcomPaymentAttempt::create(['order_id' => $order->id, 'attempted_at' => now(), 'card_masked' => null, 'result' => 'por_validar', 'method' => $method,
                'operation_ref' => $ref, 'amount' => $order->amount_due_now, 'currency' => $order->currency, 'payment_id' => $first?->id, 'idempotency_key' => $idemKey,
                'message' => 'Pago registrado: en validación por Finanzas (≤ 4 h hábiles).']);
            $this->consumeHolds($order, $lines, $owner);
            $before = EcomOrder::STATUS_LABELS[$order->status];
            $order->forceFill(['status' => 'pago_por_validar', 'hold_expires_at' => null])->save();
            WebCart::whereKey($order->cart_id)->update(['status' => 'convertido', 'last_activity_at' => now()]);
            $this->audit->log('Pedido web', $order->code, 'cambio_estado', $before, "Pago por validar · {$method} · {$ref}", system: true);
            $this->notify->push('reserva.recibida', 'email', $order->customer_email, "Recibimos su pedido {$order->code}",
                "Validaremos su pago ({$ref}) en un máximo de 4 horas hábiles. Sus cupos están reservados.", $this->ref($order));
            foreach (CatalogRecipients::role('finance') as $user) {
                $this->notify->push('pago.por_validar', 'portal', $user, "Pago por validar · pedido web {$order->code}", "{$order->currency} {$order->amount_due_now} · {$method} · {$ref}", $this->ref($order));
            }

            return $attempt;
        });

        return $this->attemptResult($attempt, $order->fresh());
    }

    /**
     * Gancho de PaymentService al aplicar un pago a una CxC de una reserva web: validación de Finanzas (por validar → confirmado) o pago del saldo
     * (emite vouchers cuando todas las CxC de la reserva quedan pagadas, SIT-BR-18).
     */
    public function onPaymentApplied(Payment $payment, User $actor): void
    {
        $reservation = $payment->reservation_id ? Reservation::find($payment->reservation_id) : null;
        $order = $reservation?->ecom_order_id ? EcomOrder::where('id', $reservation->ecom_order_id)->where('sales_channel', 'sitio_web')->whereNotNull('cart_id')->first() : null;
        if (! $order) {
            return;
        }
        $lines = $order->lines()->with('reservation')->get();
        if ($order->status === 'pago_por_validar') {
            if ($this->dueNow($lines, true)->every(fn (Receivable $r) => $r->fresh()->status === 'pagada')) {
                $this->confirm($order, $lines, $actor, 'Confirmado · pago validado por Finanzas');
            }

            return;
        }
        if ($order->status === 'confirmado' && $reservation->status === Reservation::CONFIRMED && $this->fullyPaid($reservation)) {
            $vouchers = $this->builder->issueVouchers($reservation, $actor, 'ecommerce');
            if ($vouchers) {
                $this->notify->push('voucher.emitido', 'email', $order->customer_email, "Sus vouchers de {$reservation->code} están listos", count($vouchers).' voucher(s) emitido(s).', $this->ref($order));
            }
        }
    }

    /** Pedido por código con token del navegador, token de consulta (correo + código) o sesión del cliente dueño (SIT-BR-19). */
    public function orderFor(string $code, ?string $token, ?User $user = null): EcomOrder
    {
        $order = EcomOrder::where('code', $code)->first();
        if ($order && $token && hash_equals($order->access_token_hash, hash('sha256', $token))) {
            return $order;
        }
        if ($order && $token && $this->lookupTokenValid($order, $token)) {
            return $order;
        }
        if ($order && $user?->client_id && $user->client_id === $order->client_id) {
            return $order;
        }
        throw ApiException::notFound();
    }

    /** POST /public/orders/lookup: correo + RES-/WEB- exactos → token de consulta de 2 h (auditado; 5/min por IP en la ruta). */
    public function lookup(string $email, string $code, ?string $ip): array
    {
        $email = mb_strtolower(trim($email));
        $code = mb_strtoupper(trim($code));
        $order = str_starts_with($code, 'RES-')
            ? EcomOrder::whereKey(Reservation::where('code', $code)->value('ecom_order_id'))->first()
            : EcomOrder::where('code', $code)->first();
        if (! $order || mb_strtolower($order->customer_email) !== $email) {
            $this->audit->log('Consulta pública', $code, 'buscar_reserva', null, "Sin coincidencia · {$email} · IP {$ip}", system: true);
            throw new ApiException(404, 'NOT_FOUND', 'SIT-BR-19', 'No encontramos una reserva con ese correo y código. Revise los datos.');
        }
        $this->audit->log('Consulta pública', $order->code, 'buscar_reserva', null, "Acceso con correo y código · IP {$ip}", system: true);

        return ['code' => $order->code, 'token' => 'lk.'.Crypt::encryptString(json_encode(['o' => $order->id, 'exp' => now()->addHours(2)->timestamp]))];
    }

    /** Página de confirmación / gestionar mi reserva (§2): nunca costos, márgenes ni datos de otros clientes. */
    public function present(EcomOrder $order): array
    {
        $order->loadMissing(['lines.product', 'lines.departure', 'passengers', 'attempts']);
        $resIds = $order->lines->pluck('reservation_id')->filter();
        $reservations = Reservation::whereIn('id', $resIds)->get()->keyBy('id');
        $receivables = Receivable::whereIn('reservation_id', $resIds)->where('status', '<>', 'cancelada')->orderBy('due_date')->get();
        $pending = Payment::whereIn('receivable_id', $receivables->pluck('id'))->where('status', 'pendiente')->sum('amount');
        $paid = $receivables->sum(fn ($r) => (float) $r->paid_amount);
        $vouchers = Voucher::whereIn('reservation_id', $resIds)->where('status', '<>', Voucher::VOIDED)->orderBy('service_date')->get();

        return [
            'code' => $order->code, 'status' => $order->status, 'status_label' => EcomOrder::STATUS_LABELS[$order->status], 'ordered_at' => $order->ordered_at->toIso8601String(),
            'buyer' => ['name' => $order->customer_name, 'email' => $order->customer_email], 'currency' => $order->currency, 'fx_rate' => (string) $order->fx_rate,
            'locale' => $order->locale, 'pay_mode' => $order->pay_mode, 'deposit_pct' => (string) $order->deposit_pct, 'total' => (string) $order->total,
            'discount' => (string) $order->discount_amount, 'amount_due_now' => (string) $order->amount_due_now, 'paid' => Fin::money($paid), 'pending_validation' => Fin::money($pending),
            'balance' => Fin::money(max(0, (float) $order->total - $paid)), 'balance_due_date' => $order->balance_due_date?->toDateString(), 'billing' => $order->billing,
            'hold_expires_at' => $order->isOpen() ? $order->hold_expires_at?->toIso8601String() : null, 'promo_code' => $order->promo_code,
            'lines' => $order->lines->map(function (EcomOrderLine $l) use ($reservations) {
                $r = $reservations[$l->reservation_id] ?? null;

                return ['line' => $l->line_no, 'product' => $l->product?->name, 'slug' => $l->product ? SiteCatalog::slugOf($l->product) : null, 'date' => $l->service_date->toDateString(),
                    'time' => $l->departure ? substr((string) $l->departure->departure_time, 0, 5) : null, 'meeting_point' => $l->departure?->meeting_point,
                    'adults' => $l->pax_adults, 'children' => $l->pax_children, 'infants' => $l->pax_infants, 'extras' => $l->extras_snapshot, 'options' => $l->config_snapshot,
                    'total' => (string) $l->total_order_currency, 'reservation' => $r ? ['code' => $r->code, 'status' => $r->status, 'status_label' => Reservation::STATUS_LABELS[$r->status]] : null,
                    'services' => $r ? BookedService::where('reservation_id', $r->id)->whereNotIn('status', [BookedService::CANCELLED])->orderBy('created_at')->get()
                        ->map(fn (BookedService $s) => ['code' => $s->code, 'name' => $s->name, 'status' => $s->status, 'status_label' => BookedService::STATUS_LABELS[$s->status] ?? $s->status,
                            'confirmation_code' => $s->confirmation_code])->all() : []];
            })->all(),
            'receivables' => $receivables->map(fn (Receivable $r) => ['code' => $r->code, 'concept' => $r->concept, 'kind' => $r->kind, 'amount' => (string) $r->amount,
                'paid' => (string) $r->paid_amount, 'balance' => $r->balance(), 'due_date' => $r->due_date->toDateString(), 'status' => $r->status, 'status_label' => Receivable::STATUS_LABELS[$r->status]])->values()->all(),
            'vouchers' => $vouchers->map(fn (Voucher $v) => ['code' => $v->code, 'service' => $v->service_label, 'date' => $v->service_date->toDateString(), 'version' => $v->version,
                'reservation' => $reservations[$v->reservation_id]->code ?? null, 'pax' => $v->pax])->values()->all(),
            'passengers' => $order->passengers->map(fn (EcomPassenger $p) => ['name' => $p->full_name, 'type' => $p->pax_type])->all(),
            'attempts' => $order->attempts->map(fn (EcomPaymentAttempt $a) => ['at' => $a->attempted_at->toIso8601String(), 'method' => $a->method, 'card' => $a->card_masked,
                'operation_ref' => $a->operation_ref, 'result' => $a->result, 'message' => $a->message])->all(),
            'next_steps' => $this->nextSteps($order, $reservations->values()->all()),
        ];
    }

    // --- validaciones (SIT-BR-13/14/15) ---

    public static function docError(string $type, string $number): ?string
    {
        $n = strtoupper(trim($number));

        return match ($type) {
            'DNI' => preg_match('/^\d{8}$/', $n) ? null : 'El DNI tiene 8 dígitos.',
            'CE' => preg_match('/^[A-Z0-9]{9,12}$/', $n) ? null : 'El carné de extranjería tiene de 9 a 12 caracteres alfanuméricos.',
            'Pasaporte' => preg_match('/^[A-Z0-9]{6,15}$/', $n) ? null : 'El pasaporte tiene de 6 a 15 caracteres alfanuméricos.',
            default => 'Tipo de documento inválido.',
        };
    }

    public static function rucValid(string $ruc): bool
    {
        return (bool) preg_match('/^(10|15|17|20)\d{9}$/', trim($ruc));
    }

    private function buyerErrors(array $b, array $billing): array
    {
        $e = [];
        $name = trim((string) ($b['name'] ?? ''));
        if (count(preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY)) < 2) {
            $e['buyer.name'] = ['Indique nombres y apellidos.'];
        }
        if (! preg_match(EcomCheckout::EMAIL, (string) ($b['email'] ?? ''))) {
            $e['buyer.email'] = ['Ingrese un correo válido.'];
        }
        if (strlen(preg_replace('/\D/', '', (string) ($b['phone'] ?? ''))) < 7) {
            $e['buyer.phone'] = ['Ingrese un celular válido (mínimo 7 dígitos).'];
        }
        if (trim((string) ($b['country'] ?? '')) === '') {
            $e['buyer.country'] = ['Indique su país.'];
        }
        if ($err = self::docError((string) ($b['doc_type'] ?? ''), (string) ($b['doc_number'] ?? ''))) {
            $e['buyer.doc_number'] = [$err];
        }
        $type = $billing['type'] ?? 'Boleta';
        if (! in_array($type, ['Boleta', 'Factura'], true)) {
            $e['billing.type'] = ['Elija boleta o factura.'];
        } elseif ($type === 'Factura') {
            if (! self::rucValid((string) ($billing['ruc'] ?? ''))) {
                $e['billing.ruc'] = ['RUC inválido: 11 dígitos que inician en 10, 15, 17 o 20 (RT-10).'];
            }
            if (trim((string) ($billing['name'] ?? '')) === '') {
                $e['billing.name'] = ['Indique la razón social.'];
            }
            if (trim((string) ($billing['fiscal_address'] ?? '')) === '') {
                $e['billing.fiscal_address'] = ['Indique la dirección fiscal.'];
            }
        }

        return $e;
    }

    /** SIT-BR-14: pasajeros = grupo más grande del carrito; edad a la primera fecha por tipo y edad mínima del producto. */
    private function passengerErrors(WebCart $cart, array $pax): array
    {
        $cart->loadMissing('items.product');
        $need = ['ADT' => (int) $cart->items->max('pax_adults'), 'CHD' => (int) $cart->items->max('pax_children'), 'INF' => (int) $cart->items->max('pax_infants')];
        $first = Carbon::parse($cart->items->min(fn ($i) => $i->service_date->toDateString()) ?? $this->clock->today());
        $minAge = (int) $cart->items->map(fn ($i) => $i->product->minimum_age)->max();
        $e = [];
        $counts = ['ADT' => 0, 'CHD' => 0, 'INF' => 0];
        $docs = [];
        foreach (array_values($pax) as $i => $p) {
            $type = (string) ($p['type'] ?? 'ADT');
            if (! isset($counts[$type])) {
                $e["passengers.{$i}.type"] = ['Tipo de pasajero inválido.'];

                continue;
            }
            $counts[$type]++;
            foreach (['first_name' => 'los nombres', 'last_name' => 'los apellidos', 'nationality' => 'la nacionalidad'] as $f => $label) {
                if (trim((string) ($p[$f] ?? '')) === '') {
                    $e["passengers.{$i}.{$f}"] = ["Indique {$label}."];
                }
            }
            if ($err = self::docError((string) ($p['doc_type'] ?? ''), (string) ($p['doc_number'] ?? ''))) {
                $e["passengers.{$i}.doc_number"] = [$err];
            } else {
                $key = ($p['doc_type'] ?? '').':'.strtoupper(trim((string) $p['doc_number']));
                if (isset($docs[$key])) {
                    $e["passengers.{$i}.doc_number"] = ['El documento está repetido en el pedido.'];
                }
                $docs[$key] = true;
            }
            $birth = (string) ($p['birth_date'] ?? '');
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth) || $birth > $this->clock->today()->toDateString()) {
                $e["passengers.{$i}.birth_date"] = ['Indique una fecha de nacimiento válida.'];

                continue;
            }
            $age = (int) Carbon::parse($birth)->diffInYears($first);
            $ok = match ($type) {
                'ADT' => $age >= 12, 'CHD' => $age >= 2 && $age <= 11, 'INF' => $age < 2,
            };
            if (! $ok) {
                $e["passengers.{$i}.birth_date"] = [match ($type) {
                    'ADT' => 'Un adulto debe tener 12 años o más a la fecha del viaje.', 'CHD' => 'Un niño debe tener entre 2 y 11 años a la fecha del viaje.',
                    'INF' => 'Un infante debe tener menos de 2 años a la fecha del viaje.',
                }];
            } elseif ($type !== 'INF' && $age < $minAge) {
                $e["passengers.{$i}.birth_date"] = ["La edad mínima de uno de los productos es {$minAge} años."];
            }
        }
        foreach ($need as $type => $n) {
            if ($counts[$type] !== $n) {
                $label = ['ADT' => 'adulto(s)', 'CHD' => 'niño(s)', 'INF' => 'infante(s)'][$type];
                $e['passengers'] = ["Indique los datos de {$need['ADT']} adulto(s), {$need['CHD']} niño(s) y {$need['INF']} infante(s) (faltan o sobran {$label})."];
            }
        }

        return $e;
    }

    private function consentErrors(array $c): array
    {
        $e = [];
        foreach (['terms' => 'Acepte los términos y condiciones.', 'cancellation' => 'Acepte las políticas de cancelación de cada producto.',
            'privacy' => 'Acepte el tratamiento de sus datos personales (Ley 29733), incluida la transferencia a proveedores.'] as $k => $msg) {
            if (! filter_var($c[$k] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $e["consents.{$k}"] = [$msg];
            }
        }

        return $e;
    }

    private static function throwIf(array $errors): void
    {
        if ($errors) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'VALIDATION_ERROR', reset($errors)[0], [], ['errors' => $errors]);
        }
    }

    // --- creación ---

    private static function billingOf(array $billing, array $buyer): array
    {
        return ($billing['type'] ?? 'Boleta') === 'Factura'
            ? ['type' => 'Factura', 'doc_type' => 'RUC', 'doc_number' => trim((string) $billing['ruc']), 'name' => trim((string) $billing['name']), 'fiscal_address' => trim((string) $billing['fiscal_address'])]
            : ['type' => 'Boleta', 'doc_type' => $buyer['doc_type'], 'doc_number' => strtoupper(trim((string) $buyer['doc_number'])), 'name' => trim((string) $buyer['name']), 'fiscal_address' => null];
    }

    /** Cliente CRM: sesión del cliente, o por email (reutiliza), o nuevo Persona Retail con tags Web. */
    private function client(array $buyer, array $billing, User $owner, ?User $session): Client
    {
        if ($session?->client_id && ($c = Client::find($session->client_id))) {
            return $c;
        }
        $email = mb_strtolower(trim((string) $buyer['email']));
        if ($c = Client::where('email_norm', $email)->orderBy('created_at')->first()) {
            return $c;
        }
        $docType = ['DNI' => 'DNI', 'CE' => 'CE', 'Pasaporte' => 'PASAPORTE'][$buyer['doc_type']] ?? 'DNI';
        $doc = strtoupper(trim((string) $buyer['doc_number']));
        $client = Client::where('doc_number', $doc)->first() ?? $this->clients->create(['kind' => 'persona', 'name' => trim($buyer['name']), 'doc_type' => $docType, 'doc_number' => $doc,
            'email' => $email, 'phone' => $buyer['phone'], 'country' => $buyer['country'], 'segment_code' => 'retail', 'tags' => ['Web', 'Sitio web'], 'owner_id' => $owner->id], $owner, true);
        $this->audit->log('Cliente', $client->code, 'crear_desde_sitio_web', null, $email, system: true);

        return $client;
    }

    /** @return list<array> pasajeros en orden (ADT, CHD, INF) */
    private function storePassengers(EcomOrder $order, array $pax, array $buyer): array
    {
        $order_ = ['ADT' => 0, 'CHD' => 1, 'INF' => 2];
        $rows = collect(array_values($pax))->sortBy(fn ($p, $i) => $order_[$p['type'] ?? 'ADT'] * 100 + $i)->values();
        $out = [];
        foreach ($rows as $n => $p) {
            $full = trim($p['first_name']).' '.trim($p['last_name']);
            EcomPassenger::create(['order_id' => $order->id, 'position' => $n + 1, 'full_name' => mb_substr($full, 0, 150), 'first_name' => mb_substr(trim($p['first_name']), 0, 80),
                'last_name' => mb_substr(trim($p['last_name']), 0, 80), 'doc_type' => $p['doc_type'], 'doc_number' => strtoupper(trim($p['doc_number'])), 'pax_type' => $p['type'],
                'birth_date' => $p['birth_date'], 'nationality' => mb_substr(trim($p['nationality']), 0, 60), 'diet' => trim((string) ($p['diet'] ?? '')) ?: null]);
            $out[] = $p + ['full_name' => $full, 'is_buyer' => $n === 0 && mb_strtolower($full) === mb_strtolower(trim($buyer['name'])) ? true : $n === 0,
                'email' => $n === 0 ? $buyer['email'] : null, 'phone' => $n === 0 ? $buyer['phone'] : null];
        }

        return $out;
    }

    /** Línea → reserva `pendiente_pago` (servicios del producto + extras como servicios separados, SIT-BR-10) y CxC (§4.5). */
    private function createLine(EcomOrder $order, $item, array $q, Client $client, User $owner, array $passengers, string $mode, int $pct, ?string $balanceDue, string $fx): EcomOrderLine
    {
        /** @var Product $product */
        $product = $item->product;
        $currency = $order->currency;
        $conv = fn (string $v) => $this->pricing->convert($v, $q['currency'], $currency, $fx);
        $lineTotal = BigDecimal::of($conv($q['total']));
        $extrasTotal = BigDecimal::of($conv($q['extras_total']));
        $pax = $item->pax_adults + $item->pax_children;
        $date = $item->service_date->toDateString();
        $reservation = Reservation::create([
            'code' => $this->sequences->next('reservation'), 'client_id' => $client->id, 'owner_id' => $owner->id, 'sede_id' => $owner->sede_id,
            'title' => mb_substr("{$product->name} — {$order->customer_name} (sitio web)", 0, 200), 'status' => Reservation::PENDING_PAYMENT, 'channel' => 'E-commerce',
            'ecom_order_id' => $order->id, 'travel_from' => $date, 'travel_to' => Carbon::parse($date)->addDays(SiteCatalog::days($product) - 1)->toDateString(),
            'currency' => $currency, 'fx_rate' => $fx, 'total_amount' => (string) $lineTotal->toScale(2),
            'snapshot_label' => "{$product->ref} v".($product->currentVersion?->version ?? '1.0'),
            'policies_snapshot' => $product->policies ? [$product->id => ReservationService::policyOf($product)] : [],
        ]);
        $productCost = $conv($q['cost']);
        $rows = ServiceBreakdown::splitTotals($product, max(1, $pax), $productCost, (string) $lineTotal->minus($extrasTotal)->toScale(2), $currency, $fx);
        $services = $this->reservations->persistServices($reservation, $rows, $product, $date, max(1, $pax), $item->departure_id, null)->all();
        foreach ($q['extras'] as $extra) {
            $services[] = BookedService::create(BookedService::kindFor($extra['code'] === 'TRF' ? 'Traslado' : null) + [
                'reservation_id' => $reservation->id, 'code' => $this->sequences->next('booking_service'), 'product_id' => $product->id, 'provider_id' => $this->extraProvider($extra['code']),
                'name' => $extra['label'], 'service_date' => $date, 'pax' => $item->pax_adults + $item->pax_children + $item->pax_infants, 'status' => BookedService::PENDING,
                'cost_amount' => (string) BigDecimal::of($conv($extra['amount_line']))->multipliedBy('0.6')->toScale(2, RoundingMode::HalfUp), 'price_amount' => $conv($extra['amount_line']), 'is_required' => false,
            ]);
        }
        $this->builder->setServices($reservation, Reservation::PENDING_PAYMENT, $owner, $services);
        $this->passengers($reservation, $item, $passengers);
        $label = "pedido web {$order->code} — {$product->name}";
        if ($mode === 'anticipo') {
            $deposit = $lineTotal->multipliedBy($pct)->dividedBy(100, 2, RoundingMode::HalfUp);
            $this->receivables->create($reservation, "Anticipo {$pct} % — {$label}", (string) $deposit, $this->clock->today()->toDateString(), $owner, "pedido web {$order->code}", 'anticipo', $fx);
            $this->receivables->create($reservation, 'Saldo '.(100 - $pct)." % — {$label}", (string) $lineTotal->minus($deposit)->toScale(2), (string) $balanceDue, $owner, "pedido web {$order->code}", 'saldo', $fx);
        } else {
            $this->receivables->create($reservation, "Pago total — {$label}", (string) $lineTotal->toScale(2), $this->clock->today()->toDateString(), $owner, "pedido web {$order->code}", 'saldo', $fx);
        }
        $this->audit->log('Reserva', $reservation->code, 'crear_ecommerce', null, "Pendiente de pago · sitio web {$order->code} · {$product->ref} · {$currency} {$reservation->total_amount}", system: true);
        ReservationCreated::dispatch($reservation->id);

        return EcomOrderLine::create([
            'order_id' => $order->id, 'line_no' => $item->line_no, 'product_id' => $product->id, 'product_version' => (string) ($product->currentVersion?->version ?? '1.0'),
            'service_date' => $date, 'departure_id' => $item->departure_id, 'pax_adults' => $item->pax_adults, 'pax_children' => $item->pax_children, 'pax_infants' => $item->pax_infants,
            'rate_id' => $q['rate_id'], 'season_id' => $q['season_id'], 'season_factor' => $q['season_factor'], 'unit_adult' => $q['unit_adult'], 'unit_child' => $q['unit_child'],
            'units' => $q['units'], 'config_snapshot' => array_map(fn ($o) => ['group' => $o['group'], 'option' => $o['option'], 'delta_pp' => $o['delta_pp']], $q['options']),
            'extras_snapshot' => array_map(fn ($e) => ['code' => $e['code'], 'label' => $e['label'], 'amount' => $e['amount'], 'currency' => $e['currency']], $q['extras']),
            'subtotal' => $q['subtotal'], 'discount' => $q['discount'], 'extras_total' => $q['extras_total'], 'total' => $q['total'], 'currency' => $q['currency'],
            'total_order_currency' => (string) $lineTotal->toScale(2), 'promotion_id' => $q['promotion_id'], 'cost_total' => $q['cost'], 'hold_id' => $item->hold_id,
            'reservation_id' => $reservation->id,
        ]);
    }

    private function extraProvider(string $code): ?string
    {
        return $code === 'INS'
            ? Provider::where('type', 'Otros')->where('name', 'ilike', '%asist%')->where('status', 'activo')->orderBy('ref')->value('id')
            : $this->pricing->transferProduct()?->main_provider_id ?? Provider::where('type', 'Transporte')->where('status', 'activo')->orderBy('ref')->value('id');
    }

    /** Pasajeros de la reserva de la línea: los primeros N de cada tipo; el titular es el primer adulto. */
    private function passengers(Reservation $reservation, $item, array $all): void
    {
        $seq = 0;
        foreach (['ADT' => $item->pax_adults, 'CHD' => $item->pax_children, 'INF' => $item->pax_infants] as $type => $count) {
            foreach (array_slice(array_values(array_filter($all, fn ($p) => $p['type'] === $type)), 0, $count) as $p) {
                $seq++;
                Passenger::create(['reservation_id' => $reservation->id, 'seq' => $seq, 'pax_type' => $type, 'full_name' => mb_substr($p['full_name'], 0, 150),
                    'doc_type' => ['DNI' => 'DNI', 'CE' => 'CE', 'Pasaporte' => 'Pasaporte'][$p['doc_type']] ?? $p['doc_type'], 'doc_number' => strtoupper(trim($p['doc_number'])),
                    'nationality' => $p['nationality'], 'birth_date' => $p['birth_date'], 'age' => (int) Carbon::parse($p['birth_date'])->diffInYears($item->service_date),
                    'diet_notes' => trim((string) ($p['diet'] ?? '')) ?: null, 'email' => $seq === 1 ? $p['email'] : null, 'phone' => $seq === 1 ? $p['phone'] : null,
                    'relationship' => $seq === 1 ? 'Titular' : null, 'is_lead' => $seq === 1, 'is_placeholder' => false]);
            }
        }
    }

    // --- cobro ---

    /** CxC que se cobran ahora: total o anticipo de cada reserva del pedido (nunca el saldo). */
    private function dueNow($lines, bool $includePaid = false)
    {
        return Receivable::whereIn('reservation_id', $lines->pluck('reservation_id')->filter())->where('status', '<>', 'cancelada')
            ->whereNot(fn ($q) => $q->where('kind', 'saldo')->where('concept', 'like', 'Saldo %'))
            ->when(! $includePaid, fn ($q) => $q->whereIn('status', Receivable::OPEN))->orderBy('created_at')->get();
    }

    private function ensureLineHold(EcomOrder $order, EcomOrderLine $line, ?WebCart $cart): void
    {
        if (! $line->departure_id) {
            return;
        }
        $hold = $line->hold_id ? Hold::find($line->hold_id) : null;
        if ($hold && ($hold->status === 'confirmada' || ($hold->status === 'hold' && $hold->expires_at->isFuture()))) {
            return;
        }
        $item = $cart?->items()->where('line_no', $line->line_no)->first();
        if (! $item) {
            throw ApiException::rule('SIT-BR-07', 'La salida del '.$line->service_date->format('d/m/Y').' ya no tiene cupos. Actualice su carrito.');
        }
        $item = $this->carts->ensureHold($cart, $item);
        $line->forceFill(['hold_id' => $item->hold_id])->save();
        $order->forceFill(['hold_expires_at' => $item->hold_expires_at])->save();
    }

    /** Cupos: hold → confirmada (`sold += pax`) y la fila pasa a referenciar la reserva (liberación en cancelaciones). */
    private function consumeHolds(EcomOrder $order, $lines, User $owner): void
    {
        foreach ($lines as $line) {
            $hold = $line->hold_id ? Hold::find($line->hold_id) : null;
            if (! $hold || $hold->status !== 'hold') {
                continue;
            }
            $hold = $this->availability->confirm($hold, $owner);
            $res = $line->reservation;
            $hold->forceFill(['reference_type' => 'reservation', 'reference_id' => $res->id, 'reference' => "{$res->code} · {$order->code}"])->save();
            $this->audit->log('Disponibilidad', $hold->ref, 'consumir_cupos', 'hold', "{$hold->quantity} cupo(s) · {$res->code} · {$order->code}", system: true);
        }
    }

    /** Pedido confirmado: reservas confirmadas (o pendiente de proveedor) y vouchers donde todas las CxC estén pagadas (SIT-BR-18). */
    private function confirm(EcomOrder $order, $lines, User $actor, string $note): void
    {
        $vouchers = 0;
        foreach ($lines as $line) {
            $r = Reservation::find($line->reservation_id);
            if (! $r || $r->status === Reservation::CANCELLED) {
                continue;
            }
            $r = $this->builder->confirmPaid($r, $actor);
            if ($r->status === Reservation::CONFIRMED && $this->fullyPaid($r)) {
                $vouchers += count($this->builder->issueVouchers($r, $actor, 'ecommerce'));
            }
            if ($r->status === Reservation::PENDING_PROVIDER) {
                foreach (CatalogRecipients::operations() as $user) {
                    $this->notify->push('reserva.pendiente_proveedor', 'portal', $user, "Reserva web {$r->code} pendiente de proveedor", $r->title, ['related_type' => 'reservation', 'related_id' => $r->id]);
                }
            }
        }
        $before = EcomOrder::STATUS_LABELS[$order->status];
        $order->forceFill(['status' => 'confirmado', 'hold_expires_at' => null])->save();
        $this->audit->log('Pedido web', $order->code, 'cambio_estado', $before, $note, system: true);
        $this->notify->push('reserva.confirmada', 'email', $order->customer_email, "Su pedido {$order->code} está confirmado", "{$order->currency} {$order->total}", $this->ref($order));
        $this->notify->push('pedido.web', 'portal', $this->ecom->owner(), "Pedido web confirmado {$order->code}", "{$order->customer_name} · {$order->currency} {$order->total}", $this->ref($order));
        if ($vouchers) {
            $this->notify->push('voucher.emitido', 'email', $order->customer_email, "Sus vouchers del pedido {$order->code}", "{$vouchers} voucher(s) emitido(s).", $this->ref($order));
        }
    }

    private function fullyPaid(Reservation $r): bool
    {
        $rcv = $r->receivables()->where('status', '<>', 'cancelada')->get();

        return $rcv->isNotEmpty() && $rcv->every(fn (Receivable $x) => $x->status === 'pagada');
    }

    private function grantMarketing(Client $client, string $evidence): void
    {
        try {
            app(ConsentService::class)->grant(['subject_type' => 'client', 'subject_id' => $client->id, 'channel' => 'email', 'source' => 'Checkout e-commerce (casilla)',
                'evidence' => "{$evidence} · casilla no premarcada", 'policy_version' => self::POLICY_VERSION], null);
        } catch (Throwable) {
            // El consentimiento de marketing es opcional: nunca bloquea el pedido.
        }
    }

    private function lookupTokenValid(EcomOrder $order, string $token): bool
    {
        if (! str_starts_with($token, 'lk.')) {
            return false;
        }
        try {
            $data = json_decode(Crypt::decryptString(substr($token, 3)), true);
        } catch (Throwable) {
            return false;
        }

        return ($data['o'] ?? null) === $order->id && ($data['exp'] ?? 0) > now()->timestamp;
    }

    private function attemptResult(EcomPaymentAttempt $a, EcomOrder $order): array
    {
        return ['result' => $a->result, 'method' => $a->method, 'message' => $a->message, 'card' => $a->card_masked, 'operation_ref' => $a->operation_ref,
            'order' => $this->present($order)];
    }

    private function nextSteps(EcomOrder $order, array $reservations): array
    {
        $steps = [['label' => 'Pedido recibido', 'done' => true]];
        $steps[] = match ($order->status) {
            'confirmado' => ['label' => 'Pago confirmado', 'done' => true],
            'pago_por_validar' => ['label' => 'Pago en validación (máx. 4 h hábiles)', 'done' => false],
            'pago_rechazado' => ['label' => 'Pago rechazado: reintente con otra tarjeta', 'done' => false],
            default => ['label' => 'Pago pendiente', 'done' => false],
        };
        if (collect($reservations)->contains(fn ($r) => $r->status === Reservation::PENDING_PROVIDER)) {
            $steps[] = ['label' => 'Confirmación del proveedor en máximo 24 h', 'done' => false];
        }
        if ($order->pay_mode === 'anticipo') {
            $steps[] = ['label' => 'Pagar el saldo hasta el '.$order->balance_due_date?->format('d/m/Y').' desde Mi cuenta', 'done' => false];
        }
        $steps[] = ['label' => 'Vouchers por correo al completar el pago', 'done' => Voucher::whereIn('reservation_id', collect($reservations)->pluck('id'))->exists()];

        return $steps;
    }

    private function ref(EcomOrder $order): array
    {
        return ['client_id' => $order->client_id, 'related_type' => 'ecom_order', 'related_id' => $order->id];
    }
}
