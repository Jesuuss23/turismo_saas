<?php

namespace App\Http\Controllers\Api\Experience;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Experience\WidgetDomain;
use App\Services\Experience\WidgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Integración web (spec 17 §6): configuración y dominios (`channels.ecommerce.manage`), regenerar clave (`admin.api.manage`) y
 * las rutas públicas del widget (X-Qhapaq-Key + Origin verificado, o X-Tenant-Slug desde el BFF).
 */
class WidgetController extends Controller
{
    public function __construct(private readonly WidgetService $widget) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->widget->show(), 'meta' => ['can_rotate' => $request->user()->hasPermissionTo('admin.api.manage')]]);
    }

    public function update(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->widget->saveDefaults($request->all(), $request->user(), $request->header('If-Match'))]);
    }

    public function rotateKey(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->widget->rotateKey((string) $request->input('reason'), $request->user())]);
    }

    public function domains(): JsonResponse
    {
        return response()->json(['data' => $this->widget->domains()]);
    }

    public function addDomain(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->widget->addDomain($request->all(), $request->user())], 201);
    }

    public function verifyDomain(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->widget->verifyDomain($this->domain($id), $request->user())]);
    }

    public function deleteDomain(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->widget->deleteDomain($this->domain($id), $request->user())]);
    }

    // --- públicas ---

    public function publicConfig(): JsonResponse
    {
        return response()->json(['data' => $this->widget->publicConfig()]);
    }

    public function publicProduct(string $code): JsonResponse
    {
        return response()->json(['data' => $this->widget->publicProduct($code)]);
    }

    public function handoff(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->widget->handoff($request->all(), $request->header('Idempotency-Key'), $request->header('Origin'))], 201);
    }

    public function checkout(string $code): JsonResponse
    {
        return response()->json(['data' => $this->widget->checkout($code)]);
    }

    private function domain(string $id): WidgetDomain
    {
        return WidgetDomain::whereKey($id)->first() ?? throw ApiException::notFound();
    }
}
