<?php

namespace App\Http\Controllers\Api\Ops;

use App\Exceptions\ApiException;
use App\Models\Bookings\BookedService;
use App\Models\Ops\Order;
use App\Models\Ops\Voucher;
use App\Services\Ops\OpsAccess;
use App\Services\Ops\VoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Spec 05 §6.5: vouchers (PROC-035) con versión, QR firmado, uso y anulación. */
class VoucherController extends OpsController
{
    public function index(Request $request): JsonResponse
    {
        $vouchers = Voucher::with(['provider:id,name', 'reservation:id,code,client_id', 'reservation.client:id,name'])
            ->when($request->input('filter.status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('filter.provider'), fn ($q, $p) => $q->where('provider_id', $p))
            ->when($request->input('filter.reservation'), fn ($q, $r) => $q->whereHas('reservation', fn ($q) => $q->where('code', $r)))
            ->when(trim((string) $request->input('q')), fn ($q, $t) => $q->where(fn ($q) => $q->whereRaw('lower(code) like ?', ['%'.mb_strtolower($t).'%'])
                ->orWhereRaw('lower(service_label) like ?', ['%'.mb_strtolower($t).'%'])))
            ->orderByDesc('issued_at')->limit(500)->get();

        return response()->json(['data' => $vouchers->map(fn (Voucher $v) => $this->present->voucherRow($v))->all(), 'meta' => [
            'kpis' => [
                'issued' => Voucher::where('status', Voucher::ISSUED)->count(), 'used' => Voucher::where('status', Voucher::USED)->count(),
                'voided' => Voucher::where('status', Voucher::VOIDED)->count(), 'pending_issue' => $this->pendingQuery()->count(),
            ],
            'can_issue' => $this->access->can($this->actor($request), OpsAccess::VOUCHERS),
        ]]);
    }

    /** Servicios confirmados sin voucher vigente, de órdenes activas. */
    public function pendingIssue(): JsonResponse
    {
        return response()->json(['data' => $this->pendingQuery()->with(['provider:id,name', 'reservation:id,code'])->orderBy('service_date')->limit(200)->get()
            ->map(fn (BookedService $s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'date' => $s->service_date->toDateString(),
                'provider' => $s->provider?->name, 'reservation' => $s->reservation?->code, 'status' => $s->status, 'confirmation_code' => $s->confirmation_code])->all()]);
    }

    public function check(Request $request, VoucherService $vouchers): JsonResponse
    {
        $data = $request->validate(['reservation_service_id' => 'required|uuid']);

        return response()->json(['data' => $vouchers->check(BookedService::find($data['reservation_service_id']) ?? throw ApiException::notFound())]);
    }

    public function store(Request $request, VoucherService $vouchers): JsonResponse
    {
        $data = $request->validate(['reservation_service_id' => 'required|uuid', 'note' => 'nullable|string|max:500']);
        $service = BookedService::with('provider')->find($data['reservation_service_id']) ?? throw ApiException::invalid('reservation_service_id', 'Servicio no encontrado.');
        $voucher = $vouchers->issue($service, $data['note'] ?? null, $this->actor($request));

        return $this->detail($request, $vouchers, $voucher, 201);
    }

    public function show(Request $request, VoucherService $vouchers, string $voucher): JsonResponse
    {
        return $this->detail($request, $vouchers, $this->findBy(Voucher::class, $voucher));
    }

    /** Documento vigente (snapshot); la UI lo renderiza e imprime/descarga como PDF. */
    public function pdf(VoucherService $vouchers, string $voucher): JsonResponse
    {
        $v = $this->findBy(Voucher::class, $voucher);

        return response()->json(['data' => ['code' => $v->code, 'version' => $v->version, 'status' => $v->status, 'qr_token' => $v->qr_token,
            'url' => "/operaciones/vouchers/{$v->code}?doc=1", 'document' => $vouchers->snapshot($v)]]);
    }

    public function action(Request $request, VoucherService $vouchers, string $voucher, string $action): JsonResponse
    {
        $v = $this->findBy(Voucher::class, $voucher);
        if ($action !== 'resend') {
            $v->assertIfMatch($request);
        }
        $actor = $this->actor($request);
        $reason = fn () => (string) ($request->validate(['reason' => 'nullable|string|max:300'])['reason'] ?? '');
        $v = match ($action) {
            'resend' => $vouchers->resend($v, $actor),
            'versions' => $vouchers->newVersion($v, $reason(), $actor),
            'redeem' => $vouchers->redeem($v, $actor),
            'void' => $vouchers->void($v, $reason(), $actor),
        };

        return $this->detail($request, $vouchers, $v, $action === 'versions' ? 201 : 200);
    }

    private function pendingQuery()
    {
        $active = Order::whereNotIn('status', [Order::CANCELLED])->pluck('reservation_id');

        return BookedService::whereIn('reservation_id', $active)->whereIn('status', [BookedService::CONFIRMED, BookedService::IN_PROGRESS])
            ->whereNotNull('provider_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ops_vouchers')->whereColumn('ops_vouchers.reservation_service_id', 'booking_services.id')
                ->where('ops_vouchers.status', Voucher::ISSUED));
    }

    private function detail(Request $request, VoucherService $vouchers, Voucher $voucher, int $status = 200): JsonResponse
    {
        $voucher = Voucher::with(['reservation.client', 'provider'])->findOrFail($voucher->id);
        $order = Order::where('reservation_id', $voucher->reservation_id)->first();

        return response()->json(['data' => $this->present->voucherDetail($voucher, $vouchers->snapshot($voucher), $this->actor($request))
            + ['order' => $order ? ['id' => $order->id, 'code' => $order->code] : null]], $status);
    }
}
