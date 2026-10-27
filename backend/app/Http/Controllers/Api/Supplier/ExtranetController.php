<?php

namespace App\Http\Controllers\Api\Supplier;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Catalog\Provider;
use App\Models\Iam\User;
use App\Models\Supplier\RateRequest;
use App\Models\Supplier\SupplierInvoice;
use App\Services\Supplier\RateRequests;
use App\Services\Supplier\SupplierAccess;
use App\Services\Supplier\SupplierInvoices;
use App\Services\Supplier\SupplierOperations;
use App\Services\Supplier\SupplierPortal;
use App\Services\Supplier\SupplierRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Spec 06 §6: API de la extranet. El proveedor se resuelve del usuario autenticado (middleware `supplier`), nunca del request. */
class ExtranetController extends Controller
{
    public function __construct(
        private readonly SupplierAccess $access,
        private readonly SupplierPortal $portal,
    ) {}

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->me($this->user($request), $this->provider($request))]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->dashboard($this->provider($request))]);
    }

    public function requests(Request $request): JsonResponse
    {
        $state = $request->input('filter.state');
        if ($state && ! array_key_exists($state, SupplierPortal::STATES)) {
            throw ApiException::invalid('filter.state', 'Filtro no válido.');
        }

        return response()->json(['data' => $this->portal->requests($this->provider($request), $state, $request->input('q'))]);
    }

    public function request(Request $request, SupplierRequests $requests, string $service): JsonResponse
    {
        return response()->json(['data' => $this->portal->requestDetail($requests->serviceOf($this->provider($request), $service))]);
    }

    public function respond(Request $request, SupplierRequests $requests, string $service, string $action): JsonResponse
    {
        $provider = $this->provider($request);
        $s = $requests->serviceOf($provider, $service);
        match ($action) {
            'confirm' => $requests->confirm($provider, $s, $request->only(['confirmation_code', 'confirmed_by_name', 'note']), $this->user($request)),
            'reject' => $requests->reject($provider, $s, $request->only(['reason', 'note']), $this->user($request)),
            'alternative' => $requests->propose($provider, $s, $request->only(['kind', 'date', 'service_id', 'net_cost', 'hold_until', 'note']), $this->user($request)),
        };

        return response()->json(['data' => $this->portal->requestDetail($s->fresh())], $action === 'alternative' ? 201 : 200);
    }

    public function rooming(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->roomingLists($this->provider($request))]);
    }

    public function roomingAction(Request $request, SupplierOperations $ops, string $list, string $action): JsonResponse
    {
        $l = $ops->list($this->provider($request), $list);
        if ($action === 'confirm') {
            $l->assertIfMatch($request);
        }
        $l = $action === 'acknowledge' ? $ops->acknowledge($l, $this->user($request)) : $ops->confirmRooms($l, $this->user($request));

        return response()->json(['data' => $this->portal->roomingRow($l)]);
    }

    public function observe(Request $request, SupplierOperations $ops, string $room): JsonResponse
    {
        $provider = $this->provider($request);
        $incident = $ops->observe($provider, $ops->room($provider, $room), $request->only(['kind', 'impact', 'proposal']), $this->user($request));

        return response()->json(['data' => $this->portal->incidentRow($incident)], 201);
    }

    public function vouchers(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->vouchers($this->provider($request), $request->input('filter.status'), $request->input('q'))]);
    }

    public function redeem(Request $request, SupplierOperations $ops): JsonResponse
    {
        $voucher = $ops->redeem($this->provider($request), (string) $request->input('code'), $request->boolean('confirm_future'), $this->user($request), $request);

        return response()->json(['data' => $this->portal->voucherRow($voucher)]);
    }

    public function payables(Request $request): JsonResponse
    {
        $result = $this->portal->payables($this->provider($request));

        return response()->json(['data' => $result['data'], 'meta' => array_diff_key($result, ['data' => 1])]);
    }

    public function uploadInvoice(Request $request, SupplierInvoices $invoices, string $payable): JsonResponse
    {
        $provider = $this->provider($request);
        [$invoice, $warnings] = $invoices->upload($provider, $invoices->payableOf($provider, $payable), $request->only(['number', 'issue_date', 'amount', 'file']), $this->user($request));

        return response()->json(['data' => SupplierPortal::invoiceRow($invoice), 'meta' => ['warnings' => $warnings]], 201);
    }

    public function invoiceFile(Request $request, SupplierInvoices $invoices, string $invoice): Response
    {
        $i = SupplierInvoice::whereKey($invoice)->where('provider_id', $this->provider($request)->id)->first() ?? throw ApiException::notFound();

        return response($invoices->content($i) ?? '', 200, ['Content-Type' => $i->mime_type, 'Content-Disposition' => 'attachment; filename="'.addslashes($i->original_file_name).'"']);
    }

    public function incidents(Request $request): JsonResponse
    {
        $provider = $this->provider($request);

        return response()->json(['data' => $this->portal->incidentRows($provider, $request->input('filter.status')), 'meta' => [
            'services' => $this->portal->incidentServices($provider), 'types' => SupplierOperations::INCIDENT_TYPES,
        ]]);
    }

    public function incident(Request $request, string $incident): JsonResponse
    {
        $provider = $this->provider($request);

        return response()->json(['data' => $this->portal->incidentDetail($provider, $this->portal->incident($provider, $incident))]);
    }

    public function storeIncident(Request $request, SupplierOperations $ops): JsonResponse
    {
        $provider = $this->provider($request);
        $incident = $ops->report($provider, $request->only(['reservation_service_id', 'type', 'severity', 'title', 'description']), $this->user($request));

        return response()->json(['data' => $this->portal->incidentDetail($provider, $incident)], 201);
    }

    public function comment(Request $request, SupplierOperations $ops, string $incident): JsonResponse
    {
        $provider = $this->provider($request);
        $i = $this->portal->incident($provider, $incident);
        $ops->comment($i, (string) $request->input('message'), $this->user($request));

        return response()->json(['data' => $this->portal->incidentDetail($provider, $i->fresh())], 201);
    }

    public function contract(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->contract($this->provider($request))]);
    }

    public function rates(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->rates($this->provider($request)), 'meta' => [
            'reasons' => RateRequest::REASONS, 'notice_days' => RateRequests::NOTICE_DAYS,
        ]]);
    }

    public function rateRequests(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->rateRequests($this->provider($request))]);
    }

    public function storeRateRequest(Request $request, RateRequests $rates): JsonResponse
    {
        $r = $rates->create($this->provider($request), $request->only(['service_id', 'proposed_cost', 'effective_from', 'reason_type', 'reason_detail']), $this->user($request));

        return response()->json(['data' => SupplierPortal::rateRequestRow($r->load('service'))], 201);
    }

    public function withdrawRateRequest(Request $request, RateRequests $rates, string $rateRequest): JsonResponse
    {
        $r = RateRequest::whereKey($rateRequest)->where('provider_id', $this->provider($request)->id)->first() ?? throw ApiException::notFound();

        return response()->json(['data' => SupplierPortal::rateRequestRow($rates->withdraw($r, $this->user($request))->load('service'))]);
    }

    private function user(Request $request): User
    {
        return $request->user();
    }

    private function provider(Request $request): Provider
    {
        return $this->access->provider($this->user($request));
    }
}
