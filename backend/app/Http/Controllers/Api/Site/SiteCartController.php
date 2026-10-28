<?php

namespace App\Http\Controllers\Api\Site;

use App\Http\Controllers\Controller;
use App\Models\Iam\User;
use App\Services\Site\CartService;
use App\Services\Site\SiteCheckout;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Carrito y checkout del sitio (spec 15 §6.2–6.3): el token del carrito viaja en `X-Cart-Token` (cookie HttpOnly del BFF) y el del pedido en
 * `X-Order-Token`; la sesión del cliente (Bearer, opcional) autocompleta y da acceso a sus pedidos.
 */
class SiteCartController extends Controller
{
    public function __construct(private readonly CartService $carts, private readonly SiteCheckout $checkout) {}

    public function create(Request $request): JsonResponse
    {
        $result = $this->carts->create($request->all(), $this->customer($request)?->client_id);

        return response()->json(['data' => $this->carts->present($result['cart']) + ['token' => $result['token']]], 201);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->carts->present($this->cart($request))]);
    }

    public function add(Request $request): JsonResponse
    {
        $cart = $this->cart($request);
        $item = $this->carts->add($cart, $request->all());

        return response()->json(['data' => $this->carts->present($cart->fresh()) + ['added' => $item->line_no]], 201);
    }

    public function update(Request $request, int $line): JsonResponse
    {
        $cart = $this->cart($request);
        $this->carts->update($cart, $line, $request->all());

        return response()->json(['data' => $this->carts->present($cart->fresh())]);
    }

    public function remove(Request $request, int $line): JsonResponse
    {
        $cart = $this->cart($request);
        $this->carts->remove($cart, $line);

        return response()->json(['data' => $this->carts->present($cart->fresh())]);
    }

    public function rehold(Request $request, int $line): JsonResponse
    {
        $cart = $this->cart($request);
        $this->carts->rehold($cart, $line);

        return response()->json(['data' => $this->carts->present($cart->fresh())]);
    }

    public function promo(Request $request): JsonResponse
    {
        $code = $request->isMethod('DELETE') ? null : (string) $request->input('code', '');

        return response()->json(['data' => $this->carts->promo($this->cart($request), $code)]);
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->carts->present($this->carts->preferences($this->cart($request), $request->all()))]);
    }

    public function validateStep(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->checkout->validate($this->cart($request), (string) $request->input('step', ''), $request->all())]);
    }

    public function createOrder(Request $request): JsonResponse
    {
        $result = $this->checkout->createOrder($this->cart($request), $request->all(), $this->customer($request));

        return response()->json(['data' => $this->checkout->present($result['order']) + ['token' => $result['token']]], 201);
    }

    public function order(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->checkout->present($this->checkout->orderFor($code, $this->orderToken($request), $this->customer($request)))]);
    }

    public function pay(Request $request, string $code): JsonResponse
    {
        $order = $this->checkout->orderFor($code, $this->orderToken($request), $this->customer($request));
        $result = $this->checkout->pay($order, $request->all(), $request->header('Idempotency-Key'));

        return response()->json(['data' => $result], $result['result'] === 'rechazado' ? 200 : 201);
    }

    public function lookup(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->checkout->lookup((string) $request->input('email', ''), (string) $request->input('code', ''), $request->ip())]);
    }

    private function cart(Request $request)
    {
        return $this->carts->current($request->header('X-Cart-Token'));
    }

    private function orderToken(Request $request): ?string
    {
        return $request->header('X-Order-Token') ?? $request->query('t');
    }

    /** Sesión opcional del cliente: el token debe ser del mismo tenant del sitio. */
    private function customer(Request $request): ?User
    {
        $user = $request->bearerToken() ? auth('sanctum')->user() : null;

        return $user instanceof User && $user->tenant_id === app(TenantContext::class)->id() && $user->client_id ? $user : null;
    }
}
