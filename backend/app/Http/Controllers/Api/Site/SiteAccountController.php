<?php

namespace App\Http\Controllers\Api\Site;

use App\Http\Controllers\Controller;
use App\Services\Site\SiteAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Cuenta del cliente en el sitio (spec 15 §6.5): registro/login públicos; el resto con Sanctum y cliente vinculado (SIT-BR-20). */
class SiteAccountController extends Controller
{
    public function __construct(private readonly SiteAccount $account) {}

    public function login(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->account->login($request->all(), $request->ip())]);
    }

    public function register(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->account->register($request->all(), $request->ip())], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->account->logout($request->user());

        return response()->json(['data' => ['ok' => true]]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->account->me($request->user())]);
    }

    public function update(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->account->update($request->user(), $request->all())]);
    }

    public function reservations(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->account->reservations($request->user())]);
    }

    public function reservation(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->account->reservation($request->user(), $code)]);
    }

    public function vouchers(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->account->vouchers($request->user())]);
    }

    public function receivables(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->account->receivables($request->user(), $request->query('status', 'open') === 'open')]);
    }

    public function pay(Request $request, string $id): JsonResponse
    {
        $result = $this->account->pay($request->user(), $id, $request->all());

        return response()->json(['data' => $result], $result['result'] === 'aprobado' ? 201 : 200);
    }

    public function wishlist(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->account->wishlist($request->user())]);
    }

    public function wish(Request $request, string $product): JsonResponse
    {
        return response()->json(['data' => $this->account->wish($request->user(), $product, ! $request->isMethod('DELETE'))]);
    }

    public function arco(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->account->arco($request->user(), $request->all())], 201);
    }
}
