<?php

namespace App\Http\Controllers\Api\Supplier;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Bookings\BookedService;
use App\Models\Catalog\Provider;
use App\Models\Finance\Payable;
use App\Models\Iam\User;
use App\Models\Ops\Voucher;
use App\Models\Supplier\RateRequest;
use App\Models\Supplier\RequestResponse;
use App\Models\Supplier\SupplierInvoice;
use App\Services\Crm\CrmClock;
use App\Services\Supplier\ProviderUsers;
use App\Services\Supplier\RateRequests;
use App\Services\Supplier\SupplierAccess;
use App\Services\Supplier\SupplierInvoices;
use App\Services\Supplier\SupplierPortal;
use App\Services\Supplier\SupplierRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Contrapartes de la extranet en el back-office (spec 06 §6, §12-1/2): bandeja de alternativas (Operaciones), solicitudes de
 * tarifa y usuarios del proveedor (Catálogo › Proveedores) y validación de facturas en cuentas por pagar (Finanzas).
 */
class BackofficeController extends Controller
{
    public function responses(Request $request): JsonResponse
    {
        $rows = RequestResponse::with(['service.reservation', 'provider:id,name,ref', 'altService:id,name', 'responder:id,name'])
            ->when($request->input('filter.type', 'alternativa'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->input('filter.review_status', 'pendiente'), fn ($q, $s) => $s === 'todas' ? $q : $q->where('review_status', $s))
            ->latest('responded_at')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (RequestResponse $r) => [
            'id' => $r->id, 'type' => $r->type, 'provider' => $r->provider?->name, 'provider_ref' => $r->provider?->ref, 'responder' => $r->responder?->name,
            'service' => ['id' => $r->service?->id, 'code' => $r->service?->code, 'name' => $r->service?->name, 'date' => $r->service?->service_date->toDateString(),
                'status' => $r->service?->status, 'cost' => (string) $r->service?->cost_amount, 'pax' => $r->service?->pax],
            'reservation' => ['code' => $r->service?->reservation?->code, 'title' => $r->service?->reservation?->title, 'currency' => $r->service?->reservation?->currency],
            'responded_at' => $r->responded_at->toIso8601String(), 'alt_kind' => $r->alt_kind, 'alt_date' => $r->alt_date?->toDateString(),
            'alt_service' => $r->altService?->name, 'alt_net_cost' => $r->alt_net_cost !== null ? (string) $r->alt_net_cost : null, 'currency' => $r->currency,
            'hold_until' => $r->hold_until?->toDateString(), 'note' => $r->note, 'review_status' => $r->review_status,
            'review_label' => $r->review_status ? RequestResponse::REVIEW_LABELS[$r->review_status] : null, 'review_note' => $r->review_note,
            'confirmation_code' => $r->confirmation_code, 'rejection_reason' => $r->rejection_reason,
        ])->all(), 'meta' => ['pending' => RequestResponse::where('type', RequestResponse::ALTERNATIVE)->where('review_status', 'pendiente')->count()]]);
    }

    public function reviewResponse(Request $request, SupplierRequests $requests, string $response, string $action): JsonResponse
    {
        $r = RequestResponse::find($response) ?? throw ApiException::notFound();
        $r = $action === 'accept'
            ? $requests->accept($r, $request->only(['confirmation_code', 'note']), $request->user())
            : $requests->decline($r, (string) $request->input('note'), $request->user());

        return response()->json(['data' => ['id' => $r->id, 'review_status' => $r->review_status]]);
    }

    public function rateRequests(Request $request): JsonResponse
    {
        $rows = RateRequest::with(['service:id,name,ref,unit', 'provider:id,name,ref'])
            ->when($request->input('filter.status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('filter.provider'), fn ($q, $p) => $q->whereHas('provider', fn ($w) => $w->where('ref', $p)))
            ->orderByRaw("status = 'pendiente' desc")->latest('requested_at')->limit(300)->get();

        return response()->json(['data' => $rows->map(fn (RateRequest $r) => SupplierPortal::rateRequestRow($r) + [
            'provider' => $r->provider?->name, 'provider_ref' => $r->provider?->ref, 'service_ref' => $r->service?->ref, 'unit' => $r->service?->unit,
        ])->all(), 'meta' => [
            'pending' => RateRequest::where('status', RateRequest::PENDING)->count(),
            'can_review' => $request->user()->hasPermissionTo('catalog.products.manage_rates'),
        ]]);
    }

    public function reviewRateRequest(Request $request, RateRequests $rates, string $rateRequest, string $action): JsonResponse
    {
        $r = RateRequest::where(fn ($q) => Str::isUuid($rateRequest) ? $q->whereKey($rateRequest) : $q->where('code', $rateRequest))->first() ?? throw ApiException::notFound();
        $r = $action === 'approve' ? $rates->approve($r, $request->input('note'), $request->user()) : $rates->reject($r, (string) $request->input('note'), $request->user());

        return response()->json(['data' => SupplierPortal::rateRequestRow($r->load('service'))]);
    }

    public function providerUsers(string $provider): JsonResponse
    {
        $p = $this->providerByKey($provider);

        return response()->json(['data' => User::where('provider_id', $p->id)->orderBy('name')->get()->map(fn (User $u) => ProviderUsers::row($u))->all(),
            'meta' => ['roles' => SupplierAccess::ROLES]]);
    }

    public function inviteProviderUser(Request $request, ProviderUsers $users, string $provider): JsonResponse
    {
        return response()->json(['data' => ProviderUsers::row($users->invite($this->providerByKey($provider), $request->only(['name', 'email', 'provider_role']), $request->user()))], 201);
    }

    /** Finanzas › Pagos a proveedores: CxP con factura y uso del voucher (prueba de prestación, §8 VoucherRedeemed). */
    public function payables(Request $request, CrmClock $clock): JsonResponse
    {
        $rows = Payable::with(['provider:id,name,ref', 'reservation:id,code', 'service:id,status', 'invoices'])
            ->when($request->input('filter.status'), fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->where('status', '<>', 'anulada'))
            ->when($request->input('filter.provider'), fn ($q, $p) => $q->whereHas('provider', fn ($w) => $w->where('ref', $p)))
            ->when($request->input('filter.invoice') === 'registrada', fn ($q) => $q->whereHas('invoices', fn ($w) => $w->where('status', 'registrada')))
            ->orderBy('due_date')->limit(500)->get();
        $vouchers = Voucher::whereIn('reservation_service_id', $rows->pluck('reservation_service_id')->filter())->latest('issued_at')->get()->keyBy('reservation_service_id');
        $today = $clock->today()->toDateString();

        return response()->json(['data' => $rows->map(function (Payable $p) use ($vouchers, $today) {
            $v = $vouchers[$p->reservation_service_id] ?? null;

            return ['id' => $p->id, 'code' => $p->code, 'provider' => $p->provider?->name, 'provider_ref' => $p->provider?->ref, 'reservation_code' => $p->reservation?->code,
                'concept' => $p->concept, 'amount' => (string) $p->amount, 'paid' => (string) $p->paid_amount, 'balance' => $p->balance(), 'currency' => $p->currency,
                'due_date' => $p->due_date->toDateString(), 'overdue' => $p->due_date->toDateString() < $today && ! in_array($p->status, ['pagada', 'anulada'], true),
                'status' => $p->status, 'status_label' => Payable::STATUS_LABELS[$p->status],
                'service_status' => $p->service ? (BookedService::STATUS_LABELS[$p->service->status] ?? $p->service->status) : null,
                'voucher' => $v ? ['code' => $v->code, 'status' => $v->status, 'status_label' => Voucher::STATUS_LABELS[$v->status], 'used_at' => $v->used_at?->toIso8601String(), 'used_via' => $v->used_via] : null,
                'invoices' => $p->invoices->map(fn (SupplierInvoice $i) => SupplierPortal::invoiceRow($i))->all()];
        })->all(), 'meta' => [
            'invoices_to_review' => SupplierInvoice::where('status', 'registrada')->count(),
            'totals' => $rows->whereNotIn('status', ['pagada', 'anulada'])->groupBy('currency')->map(fn ($g) => number_format($g->sum(fn (Payable $p) => (float) $p->balance()), 2, '.', ''))->all(),
            'providers' => Provider::orderBy('name')->get(['ref', 'name'])->map(fn ($p) => ['ref' => $p->ref, 'name' => $p->name])->all(),
            'labels' => Payable::STATUS_LABELS,
        ]]);
    }

    public function reviewInvoice(Request $request, SupplierInvoices $invoices, string $invoice, string $action): JsonResponse
    {
        $i = SupplierInvoice::find($invoice) ?? throw ApiException::notFound();

        return response()->json(['data' => SupplierPortal::invoiceRow($invoices->review($i, $action, $request->input('note'), $request->user()))]);
    }

    public function invoiceFile(SupplierInvoices $invoices, string $invoice): Response
    {
        $i = SupplierInvoice::find($invoice) ?? throw ApiException::notFound();

        return response($invoices->content($i) ?? '', 200, ['Content-Type' => $i->mime_type, 'Content-Disposition' => 'attachment; filename="'.addslashes($i->original_file_name).'"']);
    }

    private function providerByKey(string $key): Provider
    {
        return Provider::where(fn ($q) => Str::isUuid($key) ? $q->whereKey($key) : $q->where('ref', $key))->first() ?? throw ApiException::notFound();
    }
}
