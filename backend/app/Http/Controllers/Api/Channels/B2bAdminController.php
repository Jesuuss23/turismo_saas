<?php

namespace App\Http\Controllers\Api\Channels;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductB2bRate;
use App\Models\Catalog\Rate;
use App\Models\Channel\B2bAgency;
use App\Models\Channel\B2bCreditMovement;
use App\Models\Channel\B2bPriceList;
use App\Models\Channel\B2bSettlementRequest;
use App\Services\AuditLogger;
use App\Services\Catalog\ProductParts;
use App\Services\Channels\B2bAgencyService;
use App\Services\Channels\B2bTerms;
use App\Services\Crm\CrmClock;
use App\Services\Quotes\QuotePricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Administración del canal B2B (spec 08 §6.1): agencias, crédito, listas de precios y tarifas por agencia. */
class B2bAdminController extends Controller
{
    public function __construct(private readonly B2bAgencyService $agencies, private readonly CrmClock $clock) {}

    public function summary(): JsonResponse
    {
        return response()->json(['data' => $this->agencies->summary()]);
    }

    public function index(Request $request): JsonResponse
    {
        $rows = $this->agencies->list((array) $request->input('filter', []) + ['q' => $request->input('q')]);

        return response()->json(['data' => $rows->map(fn (B2bAgency $a) => $this->row($a))->values()->all(),
            'meta' => ['price_lists' => $this->agencies->priceLists()->map(fn (B2bPriceList $l) => ['id' => $l->id, 'name' => $l->name])->all(),
                'statuses' => B2bAgency::STATUS_LABELS]]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->row($this->agencies->create($request->all(), $request->user()))], 201);
    }

    public function show(string $agency): JsonResponse
    {
        $a = $this->find($agency);
        $rates = ProductB2bRate::where('agency_id', $a->id)->with('product:id,ref,name,status')->orderBy('created_at')->get();
        $reservations = Reservation::where('channel', 'B2B')->where(fn ($q) => $q->where('agency_id', $a->id)->orWhere('client_id', $a->client_id))
            ->orderByDesc('created_at')->limit(20)->get();
        $today = $this->clock->today()->toDateString();

        return response()->json(['data' => $this->row($a) + [
            'rates' => $rates->map(fn (ProductB2bRate $r) => ['id' => $r->id, 'product' => $r->product?->name, 'product_ref' => $r->product?->ref, 'price' => (string) $r->price,
                'currency' => $r->currency, 'commission_pct' => (string) $r->commission_pct, 'is_credit' => (bool) $r->is_credit, 'valid_to' => $r->valid_to?->toDateString(),
                'expired' => $r->valid_to && $r->valid_to->toDateString() < $today])->all(),
            'reservations' => $reservations->map(fn (Reservation $r) => ['code' => $r->code, 'title' => $r->title, 'status' => $r->status,
                'status_label' => Reservation::STATUS_LABELS[$r->status] ?? $r->status, 'total' => (string) $r->total_amount, 'currency' => $r->currency,
                'travel_from' => $r->travel_from->toDateString()])->all(),
        ]]);
    }

    public function update(Request $request, string $agency): JsonResponse
    {
        $a = $this->find($agency)->assertIfMatch($request);
        $result = $this->agencies->update($a, $request->all(), $request->user());

        return response()->json(['data' => $this->row($result['agency']), 'meta' => ['warnings' => $result['warnings']]]);
    }

    public function block(Request $request, string $agency): JsonResponse
    {
        $a = $this->find($agency)->assertIfMatch($request);

        return response()->json(['data' => $this->row($this->agencies->block($a, (string) $request->input('reason', ''), $request->user()))]);
    }

    public function unblock(Request $request, string $agency): JsonResponse
    {
        $a = $this->find($agency)->assertIfMatch($request);

        return response()->json(['data' => $this->row($this->agencies->unblock($a, $request->input('reason'), $request->user()))]);
    }

    public function movements(string $agency): JsonResponse
    {
        return response()->json(['data' => $this->agencies->movements($this->find($agency))->map(fn (B2bCreditMovement $m) => [
            'id' => $m->id, 'kind' => $m->movement_type, 'amount' => (string) $m->amount, 'currency' => $m->currency, 'balance_after' => (string) $m->balance_after,
            'reservation' => $m->reservation?->code, 'payment' => $m->payment?->code, 'note' => $m->note, 'at' => $m->created_at->toIso8601String(),
        ])->all()]);
    }

    /** "Ver portal como esta agencia" (§12): previsualización interna de solo lectura, auditada. */
    public function impersonation(Request $request, AuditLogger $audit, string $agency): JsonResponse
    {
        $a = $this->find($agency);
        $audit->log('Agencia B2B', $a->code, 'ver_portal_como', null, 'Previsualización de solo lectura', actor: $request->user());

        return response()->json(['data' => ['agency' => $a->code, 'read_only' => true, 'path' => "/canales/b2b/portal/catalogo?agency={$a->code}"]]);
    }

    public function priceLists(): JsonResponse
    {
        return response()->json(['data' => $this->agencies->priceLists()->map(fn (B2bPriceList $l) => ['id' => $l->id, 'name' => $l->name, 'year' => $l->year, 'status' => $l->status,
            'agencies' => B2bAgency::where('price_list_id', $l->id)->count()])->all()]);
    }

    /** Productos B2B con precio público, tarifa general (neta) y asignaciones por agencia. */
    public function productRates(): JsonResponse
    {
        $today = $this->clock->today()->toDateString();
        $assigned = ProductB2bRate::pluck('product_id')->unique();
        $agencies = B2bAgency::get(['id', 'code', 'name', 'status', 'valid_to', 'credit_limit', 'credit_used'])->keyBy('id');
        $products = Product::where(fn ($q) => $q->where('is_b2b', true)->orWhereIn('id', $assigned))->orderBy('name')->get();

        return response()->json(['data' => $products->map(function (Product $p) use ($today, $agencies) {
            $public = app(B2bTerms::class)->publicRate($p, $today);
            $general = Rate::where('product_id', $p->id)->get()->first(fn (Rate $r) => $r->isNet() && QuotePricing::rateValidOn($r, $today));

            return ['id' => $p->id, 'ref' => $p->ref, 'name' => $p->name, 'status' => $p->status, 'is_b2b' => (bool) $p->is_b2b,
                'public_price' => $public ? (string) $public->price : null, 'public_currency' => $public?->currency,
                'general_net' => $general ? (string) $general->price : null, 'general_currency' => $general?->currency,
                'assignments' => ProductB2bRate::where('product_id', $p->id)->orderBy('created_at')->get()->map(fn (ProductB2bRate $r) => [
                    'id' => $r->id, 'agency_id' => $r->agency_id, 'agency' => $agencies[$r->agency_id]->name ?? '—', 'agency_code' => $agencies[$r->agency_id]->code ?? null,
                    'price_list' => $r->price_list, 'price' => (string) $r->price, 'currency' => $r->currency, 'commission_pct' => (string) $r->commission_pct,
                    'is_credit' => (bool) $r->is_credit, 'valid_to' => $r->valid_to?->toDateString(), 'expired' => $r->valid_to && $r->valid_to->toDateString() < $today,
                ])->all()];
        })->all(), 'meta' => ['agencies' => $agencies->values()->map(fn (B2bAgency $a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'status' => $a->effectiveStatus()])->all()]]);
    }

    /** Asignar/actualizar (B2B-BR-10, misma regla que Catálogo) o quitar la tarifa de una agencia. */
    public function saveRate(Request $request, ProductParts $parts, string $product, string $agency): JsonResponse
    {
        $p = Product::where('id', preg_match('/^[0-9a-f-]{36}$/i', $product) ? $product : null)->orWhere('ref', $product)->first() ?? throw ApiException::notFound();
        $a = $this->find($agency);
        $current = ProductB2bRate::where('product_id', $p->id)->where('agency_id', $a->id)->first();
        $data = $request->validate(['price' => 'required|numeric|gt:0', 'currency' => 'nullable|in:PEN,USD', 'commission_pct' => 'nullable|numeric|min:0|max:30',
            'is_credit' => 'nullable|boolean', 'valid_to' => 'required|date_format:Y-m-d', 'conditions' => 'nullable|string|max:200', 'restrictions' => 'nullable|string|max:200'],
            ['price.required' => 'Indique el precio neto.', 'valid_to.required' => 'Indique la vigencia.']);
        $result = $parts->saveB2bRate($p, $data + ['agency_id' => $a->id, 'price_list' => $a->priceList?->name ?? 'General', 'commission_pct' => $data['commission_pct'] ?? $a->commission_pct],
            $request->user(), $current);

        return response()->json(['data' => ['id' => $result['rate']->id], 'meta' => ['warnings' => $result['warnings'] ?? []]], $current ? 200 : 201);
    }

    public function removeRate(Request $request, ProductParts $parts, string $product, string $agency): JsonResponse
    {
        $p = Product::where('id', preg_match('/^[0-9a-f-]{36}$/i', $product) ? $product : null)->orWhere('ref', $product)->first() ?? throw ApiException::notFound();
        $row = ProductB2bRate::where('product_id', $p->id)->where('agency_id', $this->find($agency)->id)->first() ?? throw ApiException::notFound();
        $parts->removeB2bRate($p, $row, $request->user());

        return response()->json(null, 204);
    }

    public function settlements(): JsonResponse
    {
        return response()->json(['data' => B2bSettlementRequest::with('agency:id,code,name')->orderByDesc('created_at')->limit(200)->get()->map(fn (B2bSettlementRequest $s) => [
            'id' => $s->id, 'code' => $s->code, 'agency' => $s->agency?->name, 'agency_code' => $s->agency?->code, 'amount_pen' => (string) $s->amount_pen,
            'count' => count($s->commission_ids), 'status' => $s->status, 'status_label' => B2bSettlementRequest::STATUS_LABELS[$s->status], 'at' => $s->created_at->toIso8601String(),
        ])->all()]);
    }

    private function find(string $agency): B2bAgency
    {
        return B2bAgency::where('id', preg_match('/^[0-9a-f-]{36}$/i', $agency) ? $agency : null)->orWhere('code', $agency)->with('priceList')->first() ?? throw ApiException::notFound();
    }

    private function row(B2bAgency $a): array
    {
        $status = $a->effectiveStatus();

        return [
            'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'ruc' => $a->ruc, 'contact_name' => $a->contact_name, 'booking_email' => $a->booking_email,
            'price_list_id' => $a->price_list_id, 'price_list' => $a->priceList?->name, 'commission_pct' => (string) $a->commission_pct,
            'credit_currency' => $a->credit_currency, 'credit_limit' => (string) $a->credit_limit, 'credit_used' => (string) $a->credit_used, 'credit_available' => $a->available(),
            'usage_pct' => (float) $a->credit_limit > 0 ? round((float) $a->credit_used * 100 / (float) $a->credit_limit, 1) : null,
            'valid_from' => $a->valid_from?->toDateString(), 'valid_to' => $a->valid_to?->toDateString(), 'status' => $a->status, 'effective_status' => $status,
            'status_label' => B2bAgency::STATUS_LABELS[$status], 'block_reason' => $a->block_reason, 'block_note' => $a->block_note,
            'restriction' => $this->agencies->restriction($a), 'client_id' => $a->client_id, 'lock_version' => $a->lock_version,
        ];
    }
}
