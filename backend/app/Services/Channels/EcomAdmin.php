<?php

namespace App\Services\Channels;

use App\Exceptions\ApiException;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Hold;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductPromotion;
use App\Models\Channel\EcomOrder;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Catalog\AvailabilityService;
use App\Services\Catalog\ProductParts;
use App\Services\Crm\CrmClock;
use App\Services\Finance\Fin;
use App\Services\Finance\FxRates;
use App\Services\Notify;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Gestión del canal e-commerce (spec 08 §6.3, §11-14): KPIs y embudo, pedidos, enlace de pago, anulación, publicación y promociones. */
class EcomAdmin
{
    /** Embudo simulado hasta integrar analítica (§12-13): sesiones y vistas por pedido. */
    public const FUNNEL_FACTOR = ['sessions' => 42, 'views' => 18, 'checkout' => 3];

    public function __construct(
        private readonly EcomPricing $pricing,
        private readonly ChannelReservations $builder,
        private readonly ProductParts $parts,
        private readonly CrmClock $clock,
        private readonly FxRates $fx,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    public function summary(): array
    {
        $orders = EcomOrder::all(['status', 'total', 'currency', 'ordered_at']);
        $confirmed = $orders->where('status', 'confirmado');
        $rejected = EcomOrder::whereHas('attempts', fn ($q) => $q->where('result', 'rechazado'))->count();
        $n = $orders->count();

        return [
            'orders' => $n, 'confirmed' => $confirmed->count(), 'pending' => $orders->whereIn('status', EcomOrder::OPEN)->count(),
            'revenue' => Fin::money($confirmed->sum(fn ($o) => $this->fx->toPen($o->total, $o->currency))),
            'conversion' => $n ? round($confirmed->count() * 100 / max(1, $n * self::FUNNEL_FACTOR['sessions']), 2) : 0,
            'rejections' => $rejected,
            'funnel' => [
                ['step' => 'Sesiones', 'value' => $n * self::FUNNEL_FACTOR['sessions'], 'simulated' => true],
                ['step' => 'Vistas de producto', 'value' => $n * self::FUNNEL_FACTOR['views'], 'simulated' => true],
                ['step' => 'Inicio de checkout', 'value' => $n * self::FUNNEL_FACTOR['checkout'], 'simulated' => true],
                ['step' => 'Pedidos', 'value' => $n, 'simulated' => false],
                ['step' => 'Pagados', 'value' => $confirmed->count(), 'simulated' => false],
            ],
        ];
    }

    public function orders(array $filter): Collection
    {
        $q = trim((string) ($filter['q'] ?? ''));

        return EcomOrder::with(['product:id,ref,name', 'reservation:id,code,status'])->withCount('attempts')
            ->when($filter['status'] ?? null, fn ($w, $s) => $w->where('status', $s))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('code', 'ilike', "%{$q}%")->orWhere('customer_name', 'ilike', "%{$q}%")->orWhere('customer_email', 'ilike', "%{$q}%")))
            ->orderByDesc('ordered_at')->limit(300)->get();
    }

    /** ECO-BR-14: enlace de pago solo en pago_pendiente/pago_rechazado; renueva el token del comprador. */
    public function paymentLink(EcomOrder $order, User $actor): array
    {
        if (! $order->isOpen()) {
            throw ApiException::rule('ECO-BR-14', 'Solo se envía enlace de pago a pedidos con pago pendiente o rechazado.');
        }
        $token = Str::random(48);
        $order->forceFill(['access_token_hash' => hash('sha256', $token)])->save();
        $url = rtrim((string) config('turismo.frontend_url'), '/')."/sitio/pedido/{$order->code}?t={$token}";
        $this->notify->push('pago.enlace', 'email', $order->customer_email, "Complete el pago de su pedido {$order->code}",
            "Total {$order->currency} {$order->total}. Enlace: {$url}", ['client_id' => $order->client_id, 'related_type' => 'ecom_order', 'related_id' => $order->id]);
        $this->audit->log('Pedido web', $order->code, 'enviar_enlace_pago', EcomOrder::STATUS_LABELS[$order->status], "Enlace enviado a {$order->customer_email}", actor: $actor);

        return ['sent_to' => $order->customer_email];
    }

    /** ECO-BR-14 / ECO-BR-11: anular (motivo) o expirar libera cupos, cancela la reserva y la CxC sin pagos. */
    public function cancel(EcomOrder $order, string $reason, ?User $actor, bool $expired = false): EcomOrder
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ApiException::invalid('reason', 'Indique el motivo de la anulación.');
        }

        return DB::transaction(function () use ($order, $reason, $actor, $expired) {
            $order = EcomOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $order->isOpen()) {
                throw ApiException::rule('ECO-BR-14', "No se puede anular: el pedido {$order->code} está ".mb_strtolower(EcomOrder::STATUS_LABELS[$order->status]).'.');
            }
            $before = EcomOrder::STATUS_LABELS[$order->status];
            // Spec 15: un pedido del sitio tiene una reserva por línea y holds de carrito aún vivos.
            $reservations = Reservation::where('ecom_order_id', $order->id)->orWhere('id', $order->reservation_id)->get();
            foreach ($reservations->where('status', '<>', Reservation::CANCELLED) as $r) {
                $this->builder->cancel($r, $expired ? 'Hold expirado' : "Pedido web anulado: {$reason}", $actor, $expired);
            }
            foreach (Hold::whereIn('id', $order->lines()->pluck('hold_id')->filter())->where('status', 'hold')->get() as $hold) {
                app(AvailabilityService::class)->release($hold, $expired ? "Pedido {$order->code} expirado" : "Pedido {$order->code} anulado", $actor ?? $this->owner());
            }
            $order->forceFill(['status' => $expired ? 'expirado' : 'cancelado', 'cancel_reason' => mb_substr($reason, 0, 300), 'hold_expires_at' => null])->save();
            $this->audit->log('Pedido web', $order->code, $expired ? 'expirar' : 'anular', $before, ($expired ? 'Expirado' : 'Cancelado')." · {$reason}", actor: $actor, system: $expired);

            return $order->fresh();
        });
    }

    private function owner(): User
    {
        return app(EcomCheckout::class)->owner();
    }

    /** Job `ExpireEcomHolds` (cada 5 min). @return list<string> */
    public function expireHolds(): array
    {
        $done = [];
        foreach (EcomOrder::whereIn('status', EcomOrder::OPEN)->where('hold_expires_at', '<', now())->get() as $order) {
            try {
                $done[] = $this->cancel($order, 'Hold expirado sin pago aprobado', null, true)->code;
            } catch (ApiException) {
                continue;
            }
        }

        return $done;
    }

    /** Productos activos con precio web, promoción visible y publicación. */
    public function products(): array
    {
        $today = $this->clock->today()->toDateString();

        return Product::whereIn('status', [Product::ACTIVE, 'SUSPENDED', 'REVIEW', 'APPROVED'])->with('primaryDestination')->orderBy('name')->get()->map(function (Product $p) use ($today) {
            [$adult] = $this->pricing->rates($p, $today);
            $promo = $this->pricing->promotions($p)->first();

            return ['id' => $p->id, 'ref' => $p->ref, 'name' => $p->name, 'status' => $p->status, 'destination' => $p->primaryDestination?->name,
                'web_price' => $adult ? (string) $adult->price : null, 'currency' => $adult?->currency, 'rate_channel' => $adult?->channel,
                'promo' => $promo ? "{$promo->name} · ".EcomPricing::percent($promo) : null, 'published' => (bool) $p->is_ecommerce, 'featured' => (bool) $p->is_featured,
                'can_publish' => $p->status === Product::ACTIVE && $adult !== null,
                'blocker' => $p->status !== Product::ACTIVE ? 'Solo productos en estado ACTIVE pueden publicarse en la tienda (Regla transversal 18).' : ($adult ? null : 'El producto no tiene tarifa vigente.')];
        })->all();
    }

    /** ECO-BR-01. */
    public function publish(Product $product, bool $published, User $actor): Product
    {
        if ($published) {
            if ($product->status !== Product::ACTIVE) {
                throw ApiException::unprocessable('ECO-BR-01', 'Solo productos en estado ACTIVE pueden publicarse en la tienda (Regla transversal 18).');
            }
            if (! $this->pricing->rates($product, $this->clock->today()->toDateString())[0]) {
                throw ApiException::unprocessable('ECO-BR-01', 'El producto no tiene tarifa vigente.');
            }
        }
        DB::transaction(function () use ($product, $published, $actor) {
            $this->parts->setChannel($product, 'ecommerce', $published, $actor);
            $this->audit->log('Producto', $product->ref, $published ? 'publicar_ecommerce' : 'despublicar_ecommerce', $published ? 'No publicado' : 'Publicado', $published ? 'Publicado en tienda' : 'Retirado de la tienda', actor: $actor);
        });

        return $product->fresh();
    }

    public function promotions(): Collection
    {
        return ProductPromotion::with('product:id,ref,name')->orderByDesc('created_at')->get();
    }

    /** ECO-BR-15: con código → "Descuento %"; sin código → "Descuento por cantidad" (min_pax, por defecto 4). */
    public function createPromotion(Product $product, array $data, User $actor): ProductPromotion
    {
        if ($product->status !== Product::ACTIVE) {
            throw ApiException::unprocessable('ECO-BR-15', 'Solo productos ACTIVE admiten promociones del canal.');
        }
        if (trim((string) ($data['name'] ?? '')) === '') {
            throw ApiException::invalid('name', 'Indique el nombre de la promoción.');
        }
        $value = (float) ($data['value'] ?? 0);
        if ($value < 1 || $value > 50) {
            throw ApiException::invalid('value', 'El descuento debe estar entre 1 % y 50 %.');
        }
        $code = mb_strtoupper(trim((string) ($data['promo_code'] ?? '')));
        if ($code !== '' && ! preg_match('/^[A-Z0-9_-]{3,30}$/', $code)) {
            throw ApiException::invalid('promo_code', 'El código va en mayúsculas, sin espacios (3–30 caracteres).');
        }
        $promo = $this->parts->addPromotion($product, ['name' => trim($data['name']), 'kind' => $code !== '' ? 'Descuento %' : 'Descuento por cantidad', 'value' => $value,
            'channel' => 'E-commerce', 'promo_code' => $code ?: null, 'date_from' => $data['date_from'] ?? null, 'date_to' => $data['date_to'] ?? null,
            'min_pax' => $code !== '' ? null : max(2, (int) ($data['min_pax'] ?? EcomPricing::PROMO_MIN_PAX))], $actor);
        $this->audit->log('Promoción', $promo->ref, 'crear', null, "{$product->ref} · {$promo->name} · {$promo->value} %".($code ? " · {$code}" : ''), actor: $actor);

        return $promo;
    }

    public function setPromotion(ProductPromotion $promo, bool $active, User $actor): ProductPromotion
    {
        $before = $promo->effectiveStatus();
        $promo = $this->parts->setPromotionStatus($promo->product, $promo, $active, $actor);
        $this->audit->log('Promoción', $promo->ref, 'cambio_estado', $before, $promo->status, actor: $actor);

        return $promo;
    }
}
