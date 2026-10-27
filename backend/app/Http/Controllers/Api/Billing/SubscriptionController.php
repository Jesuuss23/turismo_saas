<?php

namespace App\Http\Controllers\Api\Billing;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\LimitService;
use App\Services\Platform\PlatformClock;
use App\Services\Platform\PlatformInvoiceService;
use App\Services\Platform\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Spec 13 §6 `/billing/*`: suscripción del tenant autenticado (admin.billing.manage), entitlements (autenticado). */
class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    private function tenant(Request $request): string
    {
        return $request->user()->tenant_id;
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->subscriptions->show($this->tenant($request))]);
    }

    public function usage(Request $request, LimitService $limits): JsonResponse
    {
        $month = $request->query('month');
        if ($month !== null && ! preg_match('/^\d{4}-\d{2}$/', (string) $month)) {
            throw ApiException::invalid('month', 'Use el formato AAAA-MM.');
        }

        return response()->json(['data' => $limits->usage($this->tenant($request), $month), 'month' => $month ?? PlatformClock::today()->format('Y-m'),
            'limits' => $limits->limits($this->tenant($request))]);
    }

    public function history(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->subscriptions->history($this->tenant($request))]);
    }

    public function entitlements(Request $request, EntitlementService $entitlements): JsonResponse
    {
        return response()->json(['data' => array_values($entitlements->for($this->tenant($request)))])->header('Cache-Control', 'private, max-age=300');
    }

    public function planRules(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->subscriptions->planRules($this->tenant($request))])->header('Cache-Control', 'private, max-age=300');
    }

    public function previewChange(Request $request): JsonResponse
    {
        $data = $request->validate(['plan' => 'required|string|max:30', 'cycle' => 'required|in:monthly,annual']);

        return response()->json(['data' => $this->subscriptions->previewChange($this->tenant($request), $data['plan'], $data['cycle'])]);
    }

    public function change(Request $request): JsonResponse
    {
        $data = $request->validate(['plan' => 'required|string|max:30', 'cycle' => 'required|in:monthly,annual']);

        return response()->json(['data' => $this->subscriptions->change($this->tenant($request), $data['plan'], $data['cycle'], $request->header('If-Match'), $request->user())]);
    }

    public function cancelScheduledChange(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->subscriptions->cancelScheduledChange($this->tenant($request), $request->header('If-Match'), $request->user())]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:30', 'to' => 'nullable|string|max:120', 'score' => 'nullable|integer|min:0|max:10',
            'stay' => 'nullable|string|max:200', 'comment' => 'nullable|string|max:500', 'export_requested' => 'boolean', 'accept' => 'accepted'],
            ['reason.required' => 'SUB-BR-11: indique el motivo de la cancelación.', 'accept.accepted' => 'SUB-BR-11: acepte las condiciones de cierre.']);

        return response()->json(['data' => $this->subscriptions->cancel($this->tenant($request), $data, $request->header('If-Match'), $request->user())]);
    }

    public function resume(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->subscriptions->resume($this->tenant($request), $request->header('If-Match'), $request->user())]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['auto_renew' => 'sometimes|boolean', 'billing' => 'sometimes|array', 'billing.ruc' => 'sometimes|string|max:11',
            'billing.name' => 'sometimes|string|max:200', 'billing.address' => 'sometimes|string|max:200', 'billing.ubigeo' => 'sometimes|nullable|string|max:6',
            'billing.email' => 'sometimes|string|max:150']);

        return response()->json(['data' => $this->subscriptions->update($this->tenant($request), $data, $request->header('If-Match'), $request->user())]);
    }

    public function addAddon(Request $request, string $code): JsonResponse
    {
        $data = $request->validate(['quantity' => 'nullable|integer|min:1|max:100']);

        return response()->json(['data' => $this->subscriptions->addAddon($this->tenant($request), $code, (int) ($data['quantity'] ?? 1), $request->user())]);
    }

    public function updateAddon(Request $request, string $code): JsonResponse
    {
        $data = $request->validate(['quantity' => 'required|integer|min:1|max:100']);

        return response()->json(['data' => $this->subscriptions->updateAddon($this->tenant($request), $code, (int) $data['quantity'], $request->user())]);
    }

    public function removeAddon(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->subscriptions->removeAddon($this->tenant($request), $code, $request->user())]);
    }

    public function coupon(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string|max:20']);

        return response()->json(['data' => $this->subscriptions->redeemCoupon($this->tenant($request), $data['code'], $request->user())]);
    }

    public function invoices(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->subscriptions->invoiceList($this->tenant($request), $request->only('status'))]);
    }

    public function invoice(Request $request, PlatformInvoiceService $invoices, string $number): JsonResponse
    {
        return response()->json(['data' => $this->subscriptions->invoiceDetail($invoices->byNumber($number, $this->tenant($request)))]);
    }

    public function pdf(Request $request, PlatformInvoiceService $invoices, string $number): Response
    {
        $invoice = $invoices->byNumber($number, $this->tenant($request));

        return response($invoices->pdf($invoice), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename=\"{$number}.pdf\""]);
    }

    public function pay(Request $request, string $number): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key');
        if ($key === '' || strlen($key) > 80) {
            throw new ApiException(400, 'IDEMPOTENCY_KEY_REQUIRED', 'SUB-BR-12', 'El pago exige el header Idempotency-Key');
        }

        return response()->json(['data' => $this->subscriptions->pay($this->tenant($request), $number, $key, $request->user())]);
    }

    public function resend(Request $request, string $number): JsonResponse
    {
        return response()->json(['data' => $this->subscriptions->resend($this->tenant($request), $number, $request->user())]);
    }

    public function setupIntent(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->subscriptions->setupIntent($this->tenant($request))]);
    }

    public function setDefaultMethod(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => 'required|string|max:120', 'brand' => 'nullable|string|max:20', 'exp_month' => 'required|integer',
            'exp_year' => 'required|integer', 'holder' => 'required|string|max:120']);

        return response()->json(['data' => $this->subscriptions->setDefaultMethod($this->tenant($request), $data, $request->user())]);
    }
}
