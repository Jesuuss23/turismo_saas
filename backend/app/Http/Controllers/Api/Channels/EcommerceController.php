<?php

namespace App\Http\Controllers\Api\Channels;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductPromotion;
use App\Models\Channel\EcomOrder;
use App\Services\Channels\EcomAdmin;
use App\Services\Channels\EcomCheckout;
use App\Services\Channels\EcomPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Gestión del e-commerce (spec 08 §6.3): KPIs, pedidos, enlace de pago, anulación, publicación y promociones. */
class EcommerceController extends Controller
{
    public function __construct(private readonly EcomAdmin $admin) {}

    public function summary(): JsonResponse
    {
        return response()->json(['data' => $this->admin->summary()]);
    }

    public function orders(Request $request): JsonResponse
    {
        $rows = $this->admin->orders((array) $request->input('filter', []) + ['q' => $request->input('q')]);

        return response()->json(['data' => $rows->map(fn (EcomOrder $o) => $this->row($o))->all(), 'meta' => ['statuses' => EcomOrder::STATUS_LABELS]]);
    }

    public function order(EcomCheckout $checkout, string $code): JsonResponse
    {
        $o = $this->find($code);

        return response()->json(['data' => $this->row($o) + $checkout->present($o) + ['buyer' => $o->buyer_snapshot, 'cancel_reason' => $o->cancel_reason]]);
    }

    public function paymentLink(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->admin->paymentLink($this->find($code), $request->user())]);
    }

    public function cancel(Request $request, string $code): JsonResponse
    {
        $o = $this->find($code)->assertIfMatch($request);

        return response()->json(['data' => $this->row($this->admin->cancel($o, (string) $request->input('reason', ''), $request->user()))]);
    }

    public function products(): JsonResponse
    {
        return response()->json(['data' => $this->admin->products()]);
    }

    public function publish(Request $request, string $product, string $action): JsonResponse
    {
        $p = $this->admin->publish($this->product($product), $action === 'publish', $request->user());

        return response()->json(['data' => ['id' => $p->id, 'ref' => $p->ref, 'published' => (bool) $p->is_ecommerce]]);
    }

    public function promotions(): JsonResponse
    {
        return response()->json(['data' => $this->admin->promotions()->map(fn (ProductPromotion $p) => $this->promo($p))->all()]);
    }

    public function storePromotion(Request $request, string $product): JsonResponse
    {
        return response()->json(['data' => $this->promo($this->admin->createPromotion($this->product($product), $request->all(), $request->user()))], 201);
    }

    public function promotionStatus(Request $request, string $promotion, string $action): JsonResponse
    {
        $p = ProductPromotion::with('product')->find(preg_match('/^[0-9a-f-]{36}$/i', $promotion) ? $promotion : null) ?? throw ApiException::notFound();

        return response()->json(['data' => $this->promo($this->admin->setPromotion($p, $action === 'activate', $request->user()))]);
    }

    private function find(string $code): EcomOrder
    {
        return EcomOrder::where('code', $code)->with(['product:id,ref,name', 'reservation:id,code,status'])->withCount('attempts')->first() ?? throw ApiException::notFound();
    }

    private function product(string $ref): Product
    {
        return Product::where('id', preg_match('/^[0-9a-f-]{36}$/i', $ref) ? $ref : null)->orWhere('ref', $ref)->first() ?? throw ApiException::notFound();
    }

    private function row(EcomOrder $o): array
    {
        return ['id' => $o->id, 'code' => $o->code, 'ordered_at' => $o->ordered_at->toIso8601String(), 'customer' => $o->customer_name, 'email' => $o->customer_email,
            'phone' => $o->customer_phone, 'country' => $o->customer_country, 'product' => $o->product?->name, 'product_ref' => $o->product?->ref,
            'service_date' => $o->service_date->toDateString(), 'pax' => $o->pax_total, 'total' => (string) $o->total, 'currency' => $o->currency,
            'discount' => (string) $o->discount_amount, 'promo_code' => $o->promo_code, 'status' => $o->status, 'status_label' => EcomOrder::STATUS_LABELS[$o->status],
            'reservation' => $o->reservation?->code, 'attempts' => $o->attempts_count ?? null, 'hold_expires_at' => $o->hold_expires_at?->toIso8601String(),
            'can_cancel' => $o->isOpen(), 'can_send_link' => $o->isOpen(), 'lock_version' => $o->lock_version];
    }

    private function promo(ProductPromotion $p): array
    {
        $status = $p->effectiveStatus();

        return ['id' => $p->id, 'ref' => $p->ref, 'product' => $p->product?->name, 'product_ref' => $p->product?->ref, 'name' => $p->name, 'kind' => $p->kind,
            'value' => (string) $p->value, 'label' => EcomPricing::percent($p), 'channel' => $p->channel, 'promo_code' => $p->promo_code, 'min_pax' => $p->min_pax,
            'date_from' => $p->date_from?->toDateString(), 'date_to' => $p->date_to?->toDateString(), 'status' => $status];
    }
}
