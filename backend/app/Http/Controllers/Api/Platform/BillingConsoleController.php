<?php

namespace App\Http\Controllers\Api\Platform;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Platform\CouponService;
use App\Services\Platform\DunningService;
use App\Services\Platform\MetricsService;
use App\Services\Platform\PlanAdminService;
use App\Services\Platform\PlatformCatalog;
use App\Services\Platform\PlatformClock;
use App\Services\Platform\PlatformInvoiceService;
use App\Services\Platform\TrialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Consola de facturación de la plataforma (spec 13 §2 `plataforma/facturacion/*`, Super Admin con admin.tenant.manage):
 * métricas, facturas, cobranza, trials, cupones, planes, flags, reglas por plan y uso. Solo contadores, nunca datos comerciales.
 */
class BillingConsoleController extends Controller
{
    public function __construct(
        private readonly PlatformInvoiceService $invoices,
        private readonly DunningService $dunning,
    ) {}

    public function metrics(Request $request, MetricsService $metrics): JsonResponse
    {
        return response()->json(['data' => $metrics->dashboard($request->query('from'), $request->query('to'))]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $db = DB::connection(PlatformCatalog::CONNECTION);
        $rows = $db->table('platform_invoices as i')->join('tenants as t', 't.id', '=', 'i.tenant_id')
            ->when($request->query('tenant'), fn ($q, $t) => $q->where('i.tenant_id', $t))
            ->when($request->query('status'), fn ($q, $s) => $q->where('i.status', $s))
            ->when($request->query('period'), fn ($q, $p) => $q->where('i.issue_date', '>=', "{$p}-01")->where('i.issue_date', '<', date('Y-m-d', strtotime("{$p}-01 +1 month"))))
            ->orderByDesc('i.issue_date')->orderByDesc('i.number')->limit(300)->get(['i.*', 't.code as tenant_code', 't.trade_name']);

        return response()->json(['data' => $rows->map(fn ($i) => $this->row($i))->all(),
            'tenants' => $db->table('tenants')->orderBy('code')->get(['id', 'code', 'trade_name'])->map(fn ($t) => ['value' => $t->id, 'label' => "{$t->code} · {$t->trade_name}"])->all(),
            'statuses' => PlatformCatalog::INVOICE_STATUS]);
    }

    public function invoice(string $invoice): JsonResponse
    {
        $i = $this->find($invoice);
        $db = DB::connection(PlatformCatalog::CONNECTION);

        return response()->json(['data' => $this->row($i) + [
            'lines' => $db->table('platform_invoice_lines')->where('invoice_id', $i->id)->get()->map(fn ($l) => ['description' => $l->description, 'quantity' => (float) $l->quantity,
                'unit_price' => (float) $l->unit_price, 'amount' => (float) $l->amount, 'type' => $l->line_type])->all(),
            'attempts' => $db->table('payment_attempts')->where('invoice_id', $i->id)->orderBy('attempt_no')->get()->map(fn ($a) => ['attempt_no' => (int) $a->attempt_no,
                'source' => $a->source, 'result' => $a->result, 'gateway_code' => $a->gateway_code, 'message' => $a->message, 'at' => $a->created_at])->all(),
            'calendar' => $this->dunning->calendar($i), 'bill_to' => json_decode($i->bill_to, true),
        ]]);
    }

    public function pdf(string $invoice): Response
    {
        $i = $this->find($invoice);

        return response($this->invoices->pdf($i), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$this->invoices->number($i).'.pdf"']);
    }

    public function retry(Request $request, string $invoice): JsonResponse
    {
        $result = $this->dunning->retryNow($this->find($invoice), $this->idempotency($request), $request->user());
        $ok = $result['attempt']->result === 'succeeded';

        return response()->json(['data' => $this->row($this->find($invoice)), 'message' => $ok ? 'Cobro aprobado.' : "Cobro rechazado: {$result['attempt']->message}. El calendario no cambia."]);
    }

    public function manualPayment(Request $request, string $invoice): JsonResponse
    {
        $data = $request->validate(['reference' => 'required|string|max:80', 'method' => 'required|in:transferencia,deposito']);
        $this->dunning->manualPayment($this->find($invoice), $data['reference'], $data['method'], $this->idempotency($request), $request->user());

        return response()->json(['data' => $this->row($this->find($invoice)), 'message' => 'Pago manual registrado.']);
    }

    public function void(Request $request, string $invoice): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:300']);
        $this->invoices->void($this->find($invoice), $data['reason'], $this->idempotency($request), $request->user());

        return response()->json(['data' => $this->row($this->find($invoice)), 'message' => 'Factura anulada con nota de crédito.']);
    }

    public function extend(Request $request, string $invoice): JsonResponse
    {
        $this->dunning->extend($this->find($invoice), $request->user());

        return response()->json(['data' => $this->row($this->find($invoice)), 'message' => 'Prórroga de 7 días aplicada al último reintento.']);
    }

    public function paymentLink(Request $request, string $invoice): JsonResponse
    {
        return response()->json(['data' => ['url' => $this->dunning->paymentLink($this->find($invoice), $request->user())], 'message' => 'Enlace de pago enviado.']);
    }

    /** Cobranza: facturas en dunning con su calendario y la política vigente. */
    public function dunning(): JsonResponse
    {
        $db = DB::connection(PlatformCatalog::CONNECTION);
        $rows = $db->table('platform_invoices as i')->join('tenants as t', 't.id', '=', 'i.tenant_id')->whereNotNull('i.dunning_failed_at')
            ->whereIn('i.status', ['failed', 'uncollectible', 'paid'])->orderByRaw("case i.status when 'failed' then 0 when 'uncollectible' then 1 else 2 end")
            ->orderByDesc('i.dunning_failed_at')->limit(100)->get(['i.*', 't.code as tenant_code', 't.trade_name', 't.status as tenant_status']);

        return response()->json(['data' => $rows->map(fn ($i) => $this->row($i) + ['calendar' => $this->dunning->calendar($i), 'tenant_status' => $i->tenant_status])->all(),
            'policy' => PlatformInvoiceService::policy()]);
    }

    public function runDunning(Request $request): JsonResponse
    {
        $out = $this->dunning->run($request->user());

        $results = ['paid' => 'cobrada', 'rescheduled' => 'reprogramada', 'suspended' => 'tenant suspendido', 'uncollectible' => 'vencida'];
        $detail = array_map(fn ($r) => "{$r['invoice']} ({$r['tenant']}): {$results[$r['result']]}"
            .($r['next_retry_at'] ? ' al '.Carbon::parse($r['next_retry_at'])->setTimezone(PlatformClock::TZ)->format('d/m H:i') : ''), $out);

        return response()->json(['data' => $out, 'message' => count($out).' factura(s) procesada(s)'.($detail ? ': '.implode('; ', $detail) : '').'.']);
    }

    public function policy(): JsonResponse
    {
        return response()->json(['data' => PlatformInvoiceService::policy()]);
    }

    public function updatePolicy(Request $request): JsonResponse
    {
        $data = $request->validate(['retry_days' => 'required|array|min:1|max:5', 'retry_days.*' => 'integer', 'suspend_after_last' => 'required|boolean']);

        return response()->json(['data' => $this->dunning->updatePolicy($data, $request->user()), 'message' => 'Política de cobranza actualizada.']);
    }

    public function trials(TrialService $trials): JsonResponse
    {
        return response()->json(['data' => $trials->list()]);
    }

    public function extendTrial(Request $request, TrialService $trials, string $tenant): JsonResponse
    {
        return response()->json(['data' => $trials->extend($tenant, $request->user())]);
    }

    public function convertTrial(Request $request, TrialService $trials, string $tenant): JsonResponse
    {
        $data = $request->validate(['coupon' => 'nullable|string|max:20']);

        return response()->json(['data' => $trials->convert($tenant, $data['coupon'] ?? null, $request->user())]);
    }

    public function coupons(CouponService $coupons): JsonResponse
    {
        return response()->json(['data' => $coupons->list()]);
    }

    public function createCoupon(Request $request, CouponService $coupons): JsonResponse
    {
        return response()->json(['data' => $coupons->create($request->all(), $request->user()), 'message' => 'Cupón creado.'], 201);
    }

    public function updateCoupon(Request $request, CouponService $coupons, string $coupon): JsonResponse
    {
        $data = $request->validate(['status' => 'required|in:active,inactive']);

        return response()->json(['data' => $coupons->setStatus($coupon, $data['status'], $request->user())]);
    }

    public function plans(PlanAdminService $plans): JsonResponse
    {
        return response()->json(['data' => $plans->plans()]);
    }

    public function updatePlan(Request $request, PlanAdminService $plans, string $plan): JsonResponse
    {
        $data = $request->validate(['price_month' => 'sometimes|numeric', 'price_year' => 'sometimes|numeric', 'trial_days' => 'sometimes|integer', 'limits' => 'sometimes|array']);

        return response()->json(['data' => $plans->updatePlan($plan, $data, $request->header('If-Match'), $request->user())]);
    }

    public function features(PlanAdminService $plans): JsonResponse
    {
        return response()->json(['data' => $plans->features()]);
    }

    public function setPlanFeature(Request $request, PlanAdminService $plans, string $key, string $plan): JsonResponse
    {
        $data = $request->validate(['value' => 'required|string']);

        return response()->json(['data' => $plans->setPlanFeature($key, $plan, $data['value'], $request->user())]);
    }

    public function overrides(PlanAdminService $plans): JsonResponse
    {
        return response()->json(['data' => $plans->features()['overrides']]);
    }

    public function addOverride(Request $request, PlanAdminService $plans): JsonResponse
    {
        return response()->json(['data' => $plans->addOverride($request->all(), $request->user())], 201);
    }

    public function removeOverride(Request $request, PlanAdminService $plans, string $override): JsonResponse
    {
        return response()->json(['data' => $plans->removeOverride($override, $request->user())]);
    }

    public function planRules(PlanAdminService $plans): JsonResponse
    {
        return response()->json(['data' => $plans->planRules()]);
    }

    public function setPlanRule(Request $request, PlanAdminService $plans, string $rule): JsonResponse
    {
        $data = $request->validate(['min_plan' => 'required|string|max:30']);

        return response()->json(['data' => $plans->setRuleMinPlan($rule, $data['min_plan'], $request->header('If-Match'), $request->boolean('dry_run'), $request->user())]);
    }

    public function resetPlanRules(Request $request, PlanAdminService $plans): JsonResponse
    {
        return response()->json(['data' => $plans->resetDemo($request->user())]);
    }

    public function usage(Request $request, MetricsService $metrics): JsonResponse
    {
        $filter = (array) $request->query('filter', []);

        return response()->json(['data' => $metrics->usageByTenant($filter['plan'] ?? $request->query('plan'))]);
    }

    private function row(object $i): array
    {
        return ['id' => $i->id, 'number' => $this->invoices->number($i), 'tenant_id' => $i->tenant_id, 'tenant_code' => $i->tenant_code ?? null, 'tenant_name' => $i->trade_name ?? null,
            'kind' => $i->kind, 'period_from' => $i->period_from, 'period_to' => $i->period_to, 'issue_date' => $i->issue_date, 'due_date' => $i->due_date,
            'subtotal' => (float) $i->subtotal, 'igv' => (float) $i->igv, 'total' => (float) $i->total, 'status' => $i->status,
            'status_label' => PlatformCatalog::INVOICE_STATUS[$i->status], 'sunat_status' => $i->sunat_status, 'sunat_label' => PlatformCatalog::SUNAT_STATUS[$i->sunat_status],
            'paid_at' => $i->paid_at, 'payment_ref' => $i->payment_ref, 'dunning_failed_at' => $i->dunning_failed_at, 'dunning_retries_done' => (int) $i->dunning_retries_done,
            'dunning_next_retry_at' => $i->dunning_next_retry_at, 'dunning_extended_days' => (int) $i->dunning_extended_days, 'void_reason' => $i->void_reason];
    }

    private function find(string $id): object
    {
        if (! preg_match('/^[0-9a-f-]{36}$/i', $id)) {
            return $this->invoices->byNumber($id);
        }
        $i = DB::connection(PlatformCatalog::CONNECTION)->table('platform_invoices as i')->join('tenants as t', 't.id', '=', 'i.tenant_id')->where('i.id', $id)
            ->first(['i.*', 't.code as tenant_code', 't.trade_name']);

        return $i ?? throw ApiException::notFound();
    }

    private function idempotency(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        if ($key === '' || strlen($key) > 80) {
            throw new ApiException(400, 'IDEMPOTENCY_KEY_REQUIRED', 'SUB-BR-12', 'Esta operación exige el header Idempotency-Key');
        }

        return $key;
    }
}
