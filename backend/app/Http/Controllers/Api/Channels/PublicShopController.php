<?php

namespace App\Http\Controllers\Api\Channels;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Catalog\ConfigGroup;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductPromotion;
use App\Models\Channel\EcomOrder;
use App\Services\Bookings\BookingInventory;
use App\Services\Channels\B2bPortal;
use App\Services\Channels\EcomCheckout;
use App\Services\Channels\EcomPricing;
use App\Services\Crm\CrmClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API pública de la tienda (spec 08 §6.3, consumida por la espec. 15): catálogo publicado, precio, disponibilidad, pedido con hold,
 * intento de pago con la pasarela sandbox y estado del pedido con el token del comprador. Tenant por host / `X-Tenant-Slug`.
 */
class PublicShopController extends Controller
{
    public function __construct(private readonly EcomCheckout $checkout, private readonly EcomPricing $pricing, private readonly CrmClock $clock) {}

    public function products(Request $request): JsonResponse
    {
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $rows = $this->checkout->published()
            ->when($q !== '', fn ($c) => $c->filter(fn (Product $p) => str_contains(mb_strtolower($p->name.' '.$p->primaryDestination?->name), $q)))
            ->when($request->query('category'), fn ($c, $cat) => $c->filter(fn (Product $p) => $p->category?->name === $cat || $p->category_id === $cat))
            ->map(fn (Product $p) => $this->card($p));
        $rows = match ($request->query('sort')) {
            'price' => $rows->sortBy(fn ($r) => (float) $r['price_from']),
            '-price' => $rows->sortByDesc(fn ($r) => (float) $r['price_from']),
            'name' => $rows->sortBy('name'),
            default => $rows,
        };

        return response()->json(['data' => $rows->values()->all()]);
    }

    public function product(B2bPortal $portal, string $slug): JsonResponse
    {
        $p = $this->checkout->findPublished($slug);
        $p->loadMissing(['inclusions', 'media', 'primaryDestination', 'category']);

        return response()->json(['data' => $this->card($p) + [
            'description' => $p->long_description, 'min_pax' => $p->minimum_passengers, 'max_pax' => $p->maximum_passengers, 'min_age' => $p->minimum_age,
            'advance_min_days' => $p->booking_advance_min_days, 'advance_max_days' => $p->booking_advance_max_days, 'by_departure' => BookingInventory::bySalida($p),
            'includes' => $p->inclusions->where('kind', 'include')->pluck('text')->values()->all(), 'excludes' => $p->inclusions->where('kind', 'exclude')->pluck('text')->values()->all(),
            'config' => ConfigGroup::where('product_id', $p->id)->with('options')->orderBy('sort_order')->get()->map(fn (ConfigGroup $g) => ['id' => $g->id, 'name' => $g->name,
                'options' => $g->options->map(fn ($o) => ['id' => $o->id, 'label' => $o->label, 'delta_pp' => (string) $o->price_delta])->all()])->all(),
            'departures' => BookingInventory::bySalida($p) ? $portal->departures($p) : [],
            'promotions' => $this->pricing->promotions($p)->whereNull('promo_code')->map(fn (ProductPromotion $x) => ['name' => $x->name, 'label' => EcomPricing::percent($x),
                'min_pax' => $x->min_pax ?? EcomPricing::PROMO_MIN_PAX])->values()->all(),
            'seo' => ['title' => $p->seo_title ?: $p->name, 'description' => $p->seo_description ?: $p->short_description],
        ]]);
    }

    public function price(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->checkout->price($this->checkout->findPublished((string) $request->input('product', '')), $request->all())]);
    }

    public function availability(Request $request): JsonResponse
    {
        $check = $this->checkout->availability($this->checkout->findPublished((string) $request->input('product', '')), $request->all());

        return response()->json(['data' => ['ok' => $check['ok'], 'message' => $check['message'], 'date' => $check['date'], 'on_request' => $check['on_request'],
            'departure_id' => $check['departure']?->id]]);
    }

    public function createOrder(Request $request): JsonResponse
    {
        $result = $this->checkout->createOrder($request->all());

        return response()->json(['data' => $this->checkout->present($result['order']) + ['token' => $result['token']]], 201);
    }

    public function pay(Request $request, string $code): JsonResponse
    {
        $order = $this->checkout->orderFor($code, $this->token($request));
        $attempt = $this->checkout->pay($order, (array) $request->input('card', $request->all()));

        return response()->json(['data' => ['result' => $attempt->result, 'message' => $attempt->message, 'card' => $attempt->card_masked,
            'order' => $this->checkout->present($order->fresh())]], $attempt->result === 'aprobado' ? 201 : 200);
    }

    public function order(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->checkout->present($this->checkout->orderFor($code, $this->token($request)))]);
    }

    /** Webhook de la pasarela (§6.3): firma HMAC-SHA256 del cuerpo en `X-Signature`; idempotente por `gateway_txn_id`. */
    public function webhook(Request $request, string $provider): JsonResponse
    {
        $expected = hash_hmac('sha256', $request->getContent(), (string) config('turismo.payments.webhook_secret'));
        if ($provider !== 'sandbox' || ! hash_equals($expected, (string) $request->header('X-Signature'))) {
            throw new ApiException(401, 'INVALID_SIGNATURE', 'ECO-BR-16', 'Firma del webhook inválida.');
        }
        $data = $request->validate(['order' => 'required|string', 'txn_id' => 'required|string|max:80', 'result' => 'required|in:aprobado,rechazado',
            'last4' => 'required|digits:4', 'code' => 'nullable|string|max:10', 'message' => 'nullable|string|max:200']);
        $order = EcomOrder::where('code', $data['order'])->first() ?? throw ApiException::notFound();
        $attempt = $this->checkout->process($order, $data);

        return response()->json(['data' => ['order' => $order->code, 'result' => $attempt->result, 'status' => $order->fresh()->status]]);
    }

    private function token(Request $request): ?string
    {
        return $request->header('X-Order-Token') ?? $request->query('t') ?? $request->input('token');
    }

    private function card(Product $p): array
    {
        $today = $this->clock->today()->toDateString();
        [$adult] = $this->pricing->rates($p, $today);
        $quote = $adult ? $this->pricing->quote($p, $today, ['adults' => 1]) : null;
        $image = $p->relationLoaded('media') ? $p->media->sortByDesc('is_main')->first() : null;

        return ['id' => $p->id, 'ref' => $p->ref, 'slug' => $p->slug ?: $p->ref, 'name' => $p->name, 'summary' => $p->short_description,
            'destination' => $p->primaryDestination?->name, 'category' => $p->category?->name, 'duration' => "{$p->duration_value} {$p->duration_unit}",
            'price_from' => $quote['adult_unit'] ?? null, 'list_price' => $quote['list_price'] ?? null, 'currency' => $quote['currency'] ?? null, 'featured' => (bool) $p->is_featured,
            'image' => $image ? ['path' => $image->storage_path, 'alt' => $image->alt_text] : null];
    }
}
