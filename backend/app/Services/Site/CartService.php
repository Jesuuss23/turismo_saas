<?php

namespace App\Services\Site;

use App\Exceptions\ApiException;
use App\Models\Catalog\Departure;
use App\Models\Catalog\Hold;
use App\Models\Catalog\Product;
use App\Models\Channel\WebCart;
use App\Models\Channel\WebCartItem;
use App\Services\AuditLogger;
use App\Services\Bookings\BookingInventory;
use App\Services\Catalog\AvailabilityService;
use App\Services\Channels\EcomCheckout;
use App\Services\Crm\CrmClock;
use App\Services\SequenceService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Carrito multiproducto del sitio (spec 15 §4.1, SIT-BR-05/06/08/09/12): token opaco en cookie (solo el SHA-256 en BD), hold automático de
 * adultos + niños al añadir una línea con salida, «Volver a bloquear», código promocional a nivel de carrito y precio recalculado en cada lectura.
 */
class CartService
{
    public function __construct(
        private readonly SiteCatalog $catalog,
        private readonly SitePricing $pricing,
        private readonly AvailabilityService $availability,
        private readonly EcomCheckout $checkout,
        private readonly SequenceService $sequences,
        private readonly CrmClock $clock,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{cart: WebCart, token: string} */
    public function create(array $data, ?string $clientId = null): array
    {
        $token = Str::random(48);
        $utm = isset($data['utm']) && is_array($data['utm']) ? array_intersect_key($data['utm'], array_flip(['source', 'medium', 'campaign', 'term', 'content', 'gclid', 'fbclid'])) : null;
        $cart = WebCart::create([
            'code' => $this->sequences->next('web_cart'), 'token_hash' => hash('sha256', $token), 'crm_client_id' => $clientId,
            'currency' => in_array($data['currency'] ?? null, SitePricing::CURRENCIES, true) ? $data['currency'] : 'PEN',
            'locale' => in_array($data['locale'] ?? null, ['es', 'en'], true) ? $data['locale'] : 'es', 'status' => 'activo', 'utm' => $utm ?: null, 'last_activity_at' => now(),
        ]);

        return ['cart' => $cart, 'token' => $token];
    }

    public function find(?string $token): ?WebCart
    {
        return $token ? WebCart::where('token_hash', hash('sha256', $token))->where('status', 'activo')->first() : null;
    }

    public function current(?string $token): WebCart
    {
        return $this->find($token) ?? throw new ApiException(404, 'CART_NOT_FOUND', 'CART_NOT_FOUND', 'Su carrito venció o ya fue pagado. Empiece uno nuevo.');
    }

    /** SIT-BR-05: composición de pasajeros de una línea. */
    public function validatePax(Product $p, int $adults, int $children, int $infants): void
    {
        if ($adults < 1) {
            throw ApiException::invalid('adults', 'Debe viajar al menos un adulto.');
        }
        if ($children < 0 || $infants < 0) {
            throw ApiException::invalid('children', 'Cantidad de pasajeros inválida.');
        }
        if ($infants > $adults) {
            throw ApiException::invalid('infants', 'Cada infante debe viajar con un adulto.');
        }
        if ($children > 0 && $p->minimum_age >= 12) {
            throw ApiException::invalid('children', "Este producto no admite niños (edad mínima {$p->minimum_age} años).");
        }
        if ($infants > 0 && $p->minimum_age > 0) {
            throw ApiException::invalid('infants', "Este producto no admite infantes (edad mínima {$p->minimum_age} años).");
        }
        $pax = $adults + $children;
        if ($pax < $p->minimum_passengers || $pax > $p->maximum_passengers) {
            throw ApiException::invalid('adults', "Entre {$p->minimum_passengers} y {$p->maximum_passengers} pasajeros (adultos + niños) por reserva.");
        }
    }

    /** POST /public/carts/current/items: añade la línea y bloquea cupos (409 RT-4 sin cupos). */
    public function add(WebCart $cart, array $data): WebCartItem
    {
        $product = $this->catalog->find((string) ($data['product'] ?? ''));
        [$adults, $children, $infants] = [(int) ($data['adults'] ?? 1), (int) ($data['children'] ?? 0), (int) ($data['infants'] ?? 0)];
        $this->validatePax($product, $adults, $children, $infants);
        $date = (string) ($data['date'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw ApiException::invalid('date', 'Elija una fecha en el calendario.');
        }
        $status = $this->catalog->dayStatus($product, $date, $adults + $children);
        if (! $status['sellable']) {
            if (in_array($status['reason'], ['agotado', 'sin_cupo_para_el_grupo'], true)) {
                throw ApiException::rule('RT-4', "Sin disponibilidad: quedan {$status['available']} cupo(s) para el ".Carbon::parse($date)->format('d/m/Y').'.', ['available' => $status['available']]);
            }
            throw ApiException::unprocessable('SIT-BR-04', 'Esa fecha no está disponible para la venta en línea ('.str_replace('_', ' ', (string) $status['reason']).').');
        }
        $departure = null;
        if (BookingInventory::bySalida($product)) {
            $departure = ! empty($data['departure_id']) ? Departure::where('product_id', $product->id)->whereKey($data['departure_id'])->first() : null;
            $departure ??= Departure::find($status['departure']['id']);
            if (! $departure || $departure->departure_date->toDateString() !== $date) {
                throw ApiException::invalid('departure_id', 'La salida no corresponde a la fecha elegida.');
            }
        }
        $extras = ['insurance' => filter_var($data['extras']['insurance'] ?? false, FILTER_VALIDATE_BOOLEAN), 'airport_transfer' => filter_var($data['extras']['airport_transfer'] ?? false, FILTER_VALIDATE_BOOLEAN)];
        $this->pricing->line($product, ['date' => $date, 'adults' => $adults, 'children' => $children, 'infants' => $infants, 'options' => (array) ($data['options'] ?? []), 'extras' => $extras]);

        return DB::transaction(function () use ($cart, $product, $date, $departure, $adults, $children, $infants, $data, $extras) {
            $cart = WebCart::whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $item = new WebCartItem([
                'cart_id' => $cart->id, 'line_no' => (int) $cart->items()->max('line_no') + 1, 'product_id' => $product->id, 'service_date' => $date, 'departure_id' => $departure?->id,
                'pax_adults' => $adults, 'pax_children' => $children, 'pax_infants' => $infants, 'options' => (array) ($data['options'] ?? []) ?: new \stdClass, 'extras' => $extras, 'added_at' => now(),
            ]);
            if ($departure) {
                $hold = $this->hold($cart, $departure, $adults + $children);
                $item->hold_id = $hold->id;
                $item->hold_expires_at = $hold->expires_at;
            }
            $item->save();
            $cart->forceFill(['last_activity_at' => now(), 'lock_version' => $cart->lock_version + 1])->save();

            return $item;
        });
    }

    /** PATCH línea: pasajeros, opciones o extras; reajusta el hold si cambia la cantidad. */
    public function update(WebCart $cart, int $line, array $data): WebCartItem
    {
        $item = $this->item($cart, $line);
        $product = $item->product;
        $adults = (int) ($data['adults'] ?? $item->pax_adults);
        $children = (int) ($data['children'] ?? $item->pax_children);
        $infants = (int) ($data['infants'] ?? $item->pax_infants);
        $this->validatePax($product, $adults, $children, $infants);
        $extras = array_key_exists('extras', $data) ? ['insurance' => filter_var($data['extras']['insurance'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'airport_transfer' => filter_var($data['extras']['airport_transfer'] ?? false, FILTER_VALIDATE_BOOLEAN)] : $item->extras;
        $options = array_key_exists('options', $data) ? (array) $data['options'] : (array) $item->options;
        $this->pricing->line($product, ['date' => $item->service_date->toDateString(), 'adults' => $adults, 'children' => $children, 'infants' => $infants, 'options' => $options, 'extras' => $extras]);

        return DB::transaction(function () use ($cart, $item, $adults, $children, $infants, $extras, $options) {
            $qty = $adults + $children;
            if ($item->departure_id && $qty !== $item->pax_adults + $item->pax_children) {
                $old = $item->hold;
                $live = $old && $old->status === 'hold' && $old->expires_at->isFuture();
                if ($live) {
                    $this->availability->release($old, 'Cambio de pasajeros en el carrito', $this->checkout->owner());
                }
                try {
                    $hold = $this->hold($cart, $item->departure, $qty);
                } catch (ApiException $e) {
                    if ($live) {
                        $restored = $this->hold($cart, $item->departure, $old->quantity);
                        $item->forceFill(['hold_id' => $restored->id, 'hold_expires_at' => $restored->expires_at])->save();
                    }
                    throw $e;
                }
                $item->forceFill(['hold_id' => $hold->id, 'hold_expires_at' => $hold->expires_at]);
            }
            $item->forceFill(['pax_adults' => $adults, 'pax_children' => $children, 'pax_infants' => $infants, 'extras' => $extras, 'options' => $options ?: new \stdClass])->save();
            $this->touch($cart);

            return $item;
        });
    }

    /** DELETE línea: libera el hold (§4.1 «Quitar línea ⇒ hold liberada»). */
    public function remove(WebCart $cart, int $line): void
    {
        $item = $this->item($cart, $line);
        DB::transaction(function () use ($cart, $item) {
            $hold = $item->hold;
            $item->delete();
            if ($hold && $hold->status === 'hold') {
                $this->availability->release($hold, "Línea quitada del carrito {$cart->code}", $this->checkout->owner());
            }
            $this->touch($cart);
        });
    }

    /** SIT-BR-06 «Volver a bloquear»: hold nuevo si el anterior venció y hay cupos. */
    public function rehold(WebCart $cart, int $line): WebCartItem
    {
        $item = $this->item($cart, $line);

        return $this->ensureHold($cart, $item);
    }

    /** SIT-BR-07: antes de cobrar, el hold debe estar vivo; si no, se reintenta bloquear (409 si ya no hay cupos). */
    public function ensureHold(WebCart $cart, WebCartItem $item): WebCartItem
    {
        if (! $item->departure_id) {
            return $item;
        }
        $hold = $item->hold;
        if ($hold && $hold->status === 'hold' && $hold->expires_at->isFuture()) {
            return $item;
        }
        if ($hold && in_array($hold->status, ['confirmada', 'consumida'], true)) {
            return $item;
        }
        try {
            $new = $this->hold($cart, $item->departure, $item->pax_adults + $item->pax_children);
        } catch (ApiException $e) {
            if (in_array($e->errorCode, ['RT-4', 'CAT-BR-21'], true)) {
                throw ApiException::rule('SIT-BR-07', 'La salida del '.$item->service_date->format('d/m/Y').' ya no tiene cupos. Actualice su carrito.', ['line' => $item->line_no]);
            }
            throw $e;
        }
        $item->forceFill(['hold_id' => $new->id, 'hold_expires_at' => $new->expires_at])->save();

        return $item;
    }

    public function promo(WebCart $cart, ?string $code): array
    {
        $code = $code !== null ? mb_strtoupper(trim($code)) : null;
        if ($code !== null && ! preg_match('/^[A-Z0-9_-]{3,40}$/', $code)) {
            throw ApiException::invalid('code', 'Código no válido.');
        }
        $cart->forceFill(['promo_code' => $code ?: null])->save();
        $this->touch($cart);
        $view = $this->present($cart->fresh());

        return $view;
    }

    public function preferences(WebCart $cart, array $data): WebCart
    {
        $fill = [];
        if (in_array($data['currency'] ?? null, SitePricing::CURRENCIES, true)) {
            $fill['currency'] = $data['currency'];
        }
        if (in_array($data['locale'] ?? null, ['es', 'en'], true)) {
            $fill['locale'] = $data['locale'];
        }
        if (isset($data['email']) && filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $fill['email'] = mb_strtolower($data['email']);
        }
        $cart->forceFill($fill + ['last_activity_at' => now()])->save();

        return $cart;
    }

    /** Carrito con precios recalculados (RT-5) en la moneda elegida, holds y modalidades de pago (SIT-BR-09). */
    public function present(WebCart $cart): array
    {
        $cart->load(['items.product.type', 'items.product.primaryDestination', 'items.departure', 'items.hold']);
        $currency = $cart->currency;
        $fx = $this->pricing->usdRate();
        $lines = [];
        $sum = ['subtotal' => BigDecimal::zero(), 'discount' => BigDecimal::zero(), 'extras' => BigDecimal::zero(), 'total' => BigDecimal::zero()];
        $codeApplies = false;
        foreach ($cart->items as $item) {
            $q = $this->quote($item, $cart->promo_code);
            $codeApplies = $codeApplies || $q['code_applies'];
            $conv = fn (string $v) => $this->pricing->convert($v, $q['currency'], $currency, $fx);
            foreach (['subtotal' => 'subtotal', 'discount' => 'discount', 'extras' => 'extras_total', 'total' => 'total'] as $k => $src) {
                $sum[$k] = $sum[$k]->plus($conv($q[$src]));
            }
            $hold = $item->hold;
            $live = $hold && $hold->status === 'hold' && $hold->expires_at->isFuture();
            $lines[] = [
                'line' => $item->line_no, 'code' => $item->lineCode(), 'product' => ['slug' => SiteCatalog::slugOf($item->product), 'name' => $this->catalog->translation($item->product, $cart->locale)?->name ?? $item->product->name,
                    'destination' => $item->product->primaryDestination?->name, 'type' => $item->product->type?->name, 'min_age' => $item->product->minimum_age],
                'date' => $item->service_date->toDateString(), 'time' => $item->departure ? substr((string) $item->departure->departure_time, 0, 5) : null,
                'departure' => $item->departure?->ref, 'adults' => $item->pax_adults, 'children' => $item->pax_children, 'infants' => $item->pax_infants,
                'options' => $q['options'], 'extras' => $q['extras'], 'extras_flags' => $item->extras, 'season' => $q['season'],
                'rate_currency' => $q['currency'], 'unit_adult' => $conv($q['unit_adult']), 'unit_child' => $conv($q['unit_child']), 'units' => $q['units'],
                'subtotal' => $conv($q['subtotal']), 'discount' => $conv($q['discount']), 'discount_label' => $q['discount_label'], 'extras_total' => $conv($q['extras_total']), 'total' => $conv($q['total']),
                'hold' => $item->departure_id ? ['ref' => $hold?->ref, 'status' => $live ? 'hold' : ($hold?->status === 'hold' ? 'expirada' : ($hold?->status ?? 'expirada')),
                    'expires_at' => $live ? $hold->expires_at->toIso8601String() : null] : null,
                'on_request' => ! $item->departure_id,
            ];
        }
        $first = $cart->items->min(fn ($i) => $i->service_date->toDateString());
        $holdUntil = collect($lines)->pluck('hold.expires_at')->filter()->min();

        return [
            'code' => $cart->code, 'status' => $cart->status, 'currency' => $currency, 'locale' => $cart->locale, 'fx' => ['usd_pen' => $fx], 'promo_code' => $cart->promo_code,
            'promo_message' => $cart->promo_code && ! $codeApplies && $cart->items->isNotEmpty() ? 'El código no aplica a los productos de su carrito o no está vigente.' : null,
            'promo_applied' => $cart->promo_code && $codeApplies, 'lines' => $lines, 'count' => count($lines),
            'subtotal' => (string) $sum['subtotal']->toScale(2), 'discount' => (string) $sum['discount']->toScale(2), 'extras' => (string) $sum['extras']->toScale(2),
            'total' => (string) $sum['total']->toScale(2), 'hold_expires_at' => $holdUntil, 'first_date' => $first,
            'pay_modes' => $first ? $this->payModes($first, (string) $sum['total']->toScale(2)) : [], 'client_id' => $cart->crm_client_id,
            'note' => 'Precio final calculado por el servidor al pagar.',
        ];
    }

    /** Cotización de una línea con el código del carrito (en la moneda de la tarifa). */
    public function quote(WebCartItem $item, ?string $code): array
    {
        return $this->pricing->line($item->product, ['date' => $item->service_date->toDateString(), 'adults' => $item->pax_adults, 'children' => $item->pax_children,
            'infants' => $item->pax_infants, 'options' => (array) $item->options, 'extras' => (array) $item->extras], $code);
    }

    /**
     * SIT-BR-09: anticipo 30 % (≥ 30 días) o 40 % (15–29 días); < 15 días solo pago total. Saldo vence 15 días antes del inicio.
     *
     * @return list<array{mode: string, pct: int, amount: string, available: bool, reason: ?string, balance_due: ?string}>
     */
    public function payModes(string $firstDate, string $total): array
    {
        $days = (int) $this->clock->today()->diffInDays(Carbon::parse($firstDate), false);
        $pct = self::depositPct($days);
        $due = Carbon::parse($firstDate)->subDays(15)->max($this->clock->today())->toDateString();

        return [
            ['mode' => 'total', 'pct' => 100, 'amount' => $total, 'available' => true, 'reason' => null, 'balance_due' => null],
            ['mode' => 'anticipo', 'pct' => $pct ?? 0, 'amount' => $pct ? (string) BigDecimal::of($total)->multipliedBy($pct)->dividedBy(100, 2, RoundingMode::HalfUp) : '0.00',
                'available' => $pct !== null, 'reason' => $pct === null ? 'No disponible: su viaje inicia en menos de 15 días.' : null, 'balance_due' => $pct ? $due : null],
        ];
    }

    public static function depositPct(int $days): ?int
    {
        return $days >= 30 ? 30 : ($days >= 15 ? 40 : null);
    }

    public function item(WebCart $cart, int $line): WebCartItem
    {
        return WebCartItem::where('cart_id', $cart->id)->where('line_no', $line)->first() ?? throw ApiException::notFound();
    }

    private function touch(WebCart $cart): void
    {
        $cart->forceFill(['last_activity_at' => now(), 'lock_version' => $cart->lock_version + 1])->save();
    }

    /** Hold de carrito (`reference_type = cart`, §3.13) con el mensaje público de RT-4. */
    private function hold(WebCart $cart, Departure $departure, int $quantity): Hold
    {
        try {
            return $this->availability->hold($departure, ['quantity' => $quantity, 'reference' => "Carrito web {$cart->code}", 'reference_type' => 'cart', 'reference_id' => $cart->id,
                'minutes' => $this->availability->holdMinutes()], $this->checkout->owner());
        } catch (ApiException $e) {
            if ($e->errorCode === 'PRD-BR-012') {
                $free = $this->availability->available($departure->fresh());
                throw ApiException::rule('RT-4', "Sin disponibilidad: quedan {$free} cupo(s) en la salida del ".$departure->departure_date->format('d/m/Y').'. Elija otra fecha.', ['available' => $free]);
            }
            throw $e;
        }
    }
}
