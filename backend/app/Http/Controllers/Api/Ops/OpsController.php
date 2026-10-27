<?php

namespace App\Http\Controllers\Api\Ops;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Ops\OpsPresenter;
use App\Models\Bookings\BookedService;
use App\Models\Iam\User;
use App\Models\Ops\Order;
use App\Services\Ops\OpsAccess;
use App\Services\Ops\OrderService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Base de los controladores de Operaciones (spec 05 §6): búsqueda por código o uuid y respuesta 360 de la orden. */
abstract class OpsController extends Controller
{
    public function __construct(
        protected readonly OpsPresenter $present,
        protected readonly OpsAccess $access,
        protected readonly OrderService $orders,
    ) {}

    protected function actor(Request $request): User
    {
        return $request->user();
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @return T
     */
    protected function findBy(string $model, string $key, string $codeColumn = 'code'): Model
    {
        return $model::where(fn ($q) => Str::isUuid($key) ? $q->whereKey($key) : $q->where($codeColumn, $key))->first() ?? throw ApiException::notFound();
    }

    protected function order(string $key): Order
    {
        return $this->findBy(Order::class, $key);
    }

    protected function service(string $id): BookedService
    {
        return BookedService::where(fn ($q) => Str::isUuid($id) ? $q->whereKey($id) : $q->where('code', $id))->first() ?? throw ApiException::notFound();
    }

    /** Orden 360 (§2) tras cualquier acción, para que la UI refresque con un solo round-trip. */
    protected function orderResponse(Request $request, Order $order, int $status = 200, array $meta = []): JsonResponse
    {
        $order = Order::with(['reservation.client', 'coordinator'])->findOrFail($order->id);

        return response()->json(['data' => $this->present->orderDetail($order, $this->actor($request), $this->orders->closeChecks($order)), 'meta' => $meta], $status);
    }
}
