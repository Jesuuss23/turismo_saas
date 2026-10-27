<?php

namespace App\Http\Controllers\Api\Channels;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\Passenger;
use App\Models\Bookings\Reservation;
use App\Models\Channel\B2bAgency;
use App\Models\Finance\Receivable;
use App\Models\Iam\User;
use App\Models\Ops\Voucher;
use App\Services\Channels\B2bPortal;
use App\Services\Channels\B2bTerms;
use App\Services\Ops\VoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Portal de agencias (spec 08 §6.2, PROC-053): el usuario B2B ve solo su agencia (B2B-BR-16/17). Un interno con `channels.b2b.manage`
 * previsualiza con `?agency=` en solo lectura (§12: "Ver portal como" no reserva ni informa pagos).
 */
class B2bPortalController extends Controller
{
    public function __construct(private readonly B2bPortal $portal) {}

    public function me(Request $request): JsonResponse
    {
        $agency = $this->agency($request);

        return response()->json(['data' => $this->portal->me($agency), 'meta' => $this->previewMeta($request, $agency)]);
    }

    public function catalog(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->catalog($this->agency($request))]);
    }

    public function departures(Request $request, B2bTerms $terms, string $product): JsonResponse
    {
        $this->agency($request);
        $p = $terms->authorizedProducts()->where(fn ($q) => $q->where('id', preg_match('/^[0-9a-f-]{36}$/i', $product) ? $product : null)->orWhere('ref', $product))->first()
            ?? throw ApiException::notFound();

        return response()->json(['data' => $this->portal->departures($p)]);
    }

    public function quotePrice(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->quotePrice($this->agency($request), $request->all())]);
    }

    public function reserve(Request $request): JsonResponse
    {
        $agency = $this->agency($request, write: true);
        $reservation = $this->portal->reserve($agency, $request->all(), $request->user());

        return response()->json(['data' => $this->detail($agency, $reservation)], 201);
    }

    public function reservations(Request $request): JsonResponse
    {
        $agency = $this->agency($request);
        $q = trim((string) $request->input('q', ''));
        $rows = $this->portal->reservations($agency)
            ->when($request->input('filter.status'), fn ($w, $s) => $w->where('status', $s))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('code', 'ilike', "%{$q}%")->orWhere('title', 'ilike', "%{$q}%")->orWhereRaw("b2b_terms->>'agency_ref' ilike ?", ["%{$q}%"])))
            ->limit(300)->get();

        return response()->json(['data' => $rows->map(fn (Reservation $r) => $this->row($r))->all(), 'meta' => ['statuses' => Reservation::STATUS_LABELS]]);
    }

    public function reservation(Request $request, string $code): JsonResponse
    {
        $agency = $this->agency($request);
        $r = $this->portal->reservations($agency)->where('code', $code)->first() ?? throw ApiException::notFound();

        return response()->json(['data' => $this->detail($agency, $r)]);
    }

    public function voucher(Request $request, VoucherService $vouchers, string $code): JsonResponse
    {
        $agency = $this->agency($request);
        $v = Voucher::where('code', $code)->where('status', '<>', Voucher::VOIDED)->whereIn('reservation_id', $this->portal->reservations($agency)->select('booking_reservations.id'))->first()
            ?? throw ApiException::notFound();

        return response()->json(['data' => ['code' => $v->code, 'version' => $v->version, 'status_label' => Voucher::STATUS_LABELS[$v->status], 'qr_token' => $v->qr_token,
            'document' => $vouchers->snapshot($v)]]);
    }

    public function statement(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->statement($this->agency($request))]);
    }

    public function reportPayment(Request $request, string $receivable): JsonResponse
    {
        $agency = $this->agency($request, write: true);
        $r = Receivable::whereKey(preg_match('/^[0-9a-f-]{36}$/i', $receivable) ? $receivable : null)
            ->whereIn('reservation_id', $this->portal->reservations($agency)->select('booking_reservations.id'))->first() ?? throw ApiException::notFound();
        $payment = $this->portal->reportPayment($agency, $r, $request->all(), $request->user());

        return response()->json(['data' => ['code' => $payment->code, 'status' => $payment->status, 'status_label' => 'Por validar', 'amount' => (string) $payment->amount,
            'currency' => $payment->currency]], 201);
    }

    public function settle(Request $request): JsonResponse
    {
        $agency = $this->agency($request, write: true);
        $s = $this->portal->requestSettlement($agency, $request->user());

        return response()->json(['data' => ['code' => $s->code, 'amount_pen' => (string) $s->amount_pen, 'count' => count($s->commission_ids), 'status' => $s->status]], 201);
    }

    /** B2B-BR-17 / B2B-BR-02: agencia del usuario; inactiva no entra. Interno: previsualización de solo lectura. */
    private function agency(Request $request, bool $write = false): B2bAgency
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->isExternal()) {
            $agency = $user->agency_id ? B2bAgency::with('priceList')->find($user->agency_id) : null;
            if (! $agency) {
                throw ApiException::forbidden('B2B-BR-17', 'Su usuario no está vinculado a una agencia con convenio.');
            }
            if ($agency->status === 'inactiva') {
                throw ApiException::forbidden('B2B-BR-02', 'Agencia inactiva: no es posible ingresar al portal. Contacte a la agencia operadora.');
            }

            return $agency;
        }
        if (! $user->hasPermissionTo('channels.b2b.manage')) {
            throw ApiException::forbidden('FORBIDDEN', 'No tiene permiso para previsualizar el portal de agencias.', ['channels.b2b.manage']);
        }
        if ($write) {
            throw ApiException::forbidden('PREVIEW_READ_ONLY', 'Vista previa de solo lectura: la agencia debe reservar o informar pagos desde su propio usuario.');
        }
        $ref = (string) $request->query('agency', '');

        return ($ref !== '' ? B2bAgency::where('id', preg_match('/^[0-9a-f-]{36}$/i', $ref) ? $ref : null)->orWhere('code', $ref)->with('priceList')->first()
            : B2bAgency::with('priceList')->orderBy('code')->first()) ?? throw ApiException::notFound();
    }

    private function previewMeta(Request $request, B2bAgency $agency): array
    {
        if ($request->user()->isExternal()) {
            return ['preview' => false];
        }

        return ['preview' => true, 'agency' => $agency->code,
            'agencies' => B2bAgency::orderBy('code')->get()->map(fn (B2bAgency $a) => ['code' => $a->code, 'name' => $a->name, 'status' => $a->effectiveStatus(),
                'status_label' => B2bAgency::STATUS_LABELS[$a->effectiveStatus()]])->all()];
    }

    private function row(Reservation $r): array
    {
        return ['code' => $r->code, 'title' => $r->title, 'status' => $r->status, 'status_label' => Reservation::STATUS_LABELS[$r->status] ?? $r->status,
            'travel_from' => $r->travel_from->toDateString(), 'travel_to' => $r->travel_to->toDateString(), 'pax' => $r->passengers_count ?? Passenger::where('reservation_id', $r->id)->count(),
            'total' => (string) $r->total_amount, 'currency' => $r->currency, 'agency_ref' => $r->b2b_terms['agency_ref'] ?? null, 'pay_mode' => $r->b2b_terms['pay_mode'] ?? null,
            'created_at' => $r->created_at->toIso8601String()];
    }

    /** B2B-BR-16: total neto y su referencia; sin notas internas, costos ni márgenes. */
    private function detail(B2bAgency $agency, Reservation $r): array
    {
        $vouchers = Voucher::where('reservation_id', $r->id)->where('status', '<>', Voucher::VOIDED)->get()->keyBy('reservation_service_id');
        $prepay = ($r->b2b_terms['pay_mode'] ?? null) === 'prepago';

        return $this->row($r) + [
            'commission_pct' => $r->b2b_terms['commission_pct'] ?? (string) $agency->commission_pct,
            'services' => BookedService::where('reservation_id', $r->id)->orderBy('service_date')->get()->map(fn (BookedService $s) => [
                'code' => $s->code, 'name' => $s->name, 'date' => $s->service_date->toDateString(), 'time' => $s->service_time ? substr((string) $s->service_time, 0, 5) : null,
                'pax' => $s->pax, 'status' => $s->status, 'status_label' => BookedService::STATUS_LABELS[$s->status] ?? $s->status, 'confirmation_code' => $s->confirmation_code,
                'voucher' => isset($vouchers[$s->id]) ? $vouchers[$s->id]->code : null,
                'voucher_note' => isset($vouchers[$s->id]) ? null : ($prepay && $r->status === Reservation::PENDING_PAYMENT ? 'Tras el pago' : 'Pendiente de confirmación'),
            ])->all(),
            'passengers' => Passenger::where('reservation_id', $r->id)->orderBy('seq')->get()->map(fn (Passenger $p) => ['name' => $p->full_name, 'doc' => $p->doc_number ?: 'Por registrar',
                'type' => $p->pax_type, 'placeholder' => (bool) $p->is_placeholder])->all(),
            'receivables' => Receivable::where('reservation_id', $r->id)->where('status', '<>', 'cancelada')->orderBy('due_date')->get()->map(fn (Receivable $x) => [
                'id' => $x->id, 'code' => $x->code, 'concept' => $x->concept, 'amount' => (string) $x->amount, 'balance' => $x->balance(), 'currency' => $x->currency,
                'due_date' => $x->due_date->toDateString(), 'status' => $x->status, 'status_label' => Receivable::STATUS_LABELS[$x->status]])->all(),
        ];
    }
}
