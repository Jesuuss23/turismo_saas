<?php

namespace App\Http\Controllers\Api\Finance;

use App\Models\Bookings\Reservation;
use App\Models\Channel\B2bAgency;
use App\Models\Finance\Commission;
use App\Models\Finance\CommissionRule;
use App\Services\Finance\CommissionService;
use App\Services\Finance\Fin;
use App\Services\Finance\FxRates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** §6.4 Comisiones (PROC-047). */
class CommissionController extends FinanceController
{
    public function index(Request $request): JsonResponse
    {
        $rows = Commission::with(['reservation:id,code,channel', 'user:id,name', 'agency:id,name'])
            ->when($request->input('filter.status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('filter.kind'), fn ($q, $k) => $q->where('kind', $k))
            ->orderByDesc('created_at')->limit(500)->get()
            ->when($request->input('filter.beneficiary'), fn ($rows, $b) => $rows->filter(fn (Commission $c) => $c->beneficiaryName() === $b));

        return response()->json(['data' => $rows->map(fn (Commission $c) => $this->present->commission($c))->values()->all(), 'meta' => ['labels' => Commission::STATUS_LABELS]]);
    }

    public function summary(FxRates $fx): JsonResponse
    {
        $all = Commission::with(['user:id,name', 'agency:id,name'])->get();
        $pen = fn ($set) => Fin::money($set->sum(fn (Commission $c) => $fx->toPen($c->amount, $c->currency, $c->fx_rate)));
        $by = fn (string $s) => ['count' => $all->where('status', $s)->count(), 'amount' => $pen($all->where('status', $s))];
        $missing = Reservation::whereIn('status', CommissionService::ELIGIBLE)->whereNotIn('id', Commission::select('reservation_id'))->count();

        return response()->json(['data' => [
            'calculada' => $by('calculada'), 'aprobada' => $by('aprobada'), 'pagada' => $by('pagada'), 'without_calculation' => $missing,
            'by_beneficiary' => $all->groupBy(fn (Commission $c) => $c->beneficiaryName())->map(fn ($set, $name) => ['beneficiary' => $name,
                'kind' => $set->first()->kind, 'count' => $set->count(), 'amount' => $pen($set), 'pending' => $pen($set->where('status', '<>', 'pagada'))])
                ->sortByDesc(fn ($r) => (float) $r['amount'])->values()->all(),
        ]]);
    }

    public function rules(): JsonResponse
    {
        return response()->json(['data' => [
            'rules' => CommissionRule::where('active', true)->orderBy('beneficiary_kind')->get()->map(fn (CommissionRule $r) => ['label' => $r->label,
                'condition' => $r->condition, 'pct' => $r->pct, 'base' => $r->base_type, 'valid_from' => $r->valid_from?->toDateString()])->all(),
            'agencies' => B2bAgency::orderBy('name')->get()->map(fn (B2bAgency $a) => ['code' => $a->code, 'name' => $a->name, 'status' => $a->status,
                'commission_pct' => $a->commission_pct, 'credit_limit' => $a->credit_limit, 'credit_used' => $a->credit_used, 'available' => $a->available()])->all(),
        ]]);
    }

    public function show(CommissionService $commissions, string $code): JsonResponse
    {
        $c = $this->byCode(Commission::class, $code);
        $c->load(['reservation', 'user', 'agency']);

        return response()->json(['data' => $this->present->commission($c) + ['drift' => $commissions->drift($c), 'history' => Fin::historyOf('commission', $c->id),
            'audit' => $this->present->audit(['Comisión' => [$c->code]])]]);
    }

    public function calculate(Request $request, CommissionService $commissions): JsonResponse
    {
        $created = $commissions->calculatePending($this->actor($request));

        return response()->json(['data' => array_map(fn (Commission $c) => $this->present->commission($c), $created), 'meta' => ['created' => count($created)]], 201);
    }

    public function approve(Request $request, CommissionService $commissions, string $code): JsonResponse
    {
        return response()->json(['data' => $this->present->commission($commissions->approve($this->byCode(Commission::class, $code), $this->actor($request)))]);
    }

    public function recalculate(Request $request, CommissionService $commissions, string $code): JsonResponse
    {
        return response()->json(['data' => $this->present->commission($commissions->recalculate($this->byCode(Commission::class, $code), $this->actor($request)))]);
    }

    public function pay(Request $request, CommissionService $commissions, string $code): JsonResponse
    {
        return response()->json(['data' => $this->present->commission($commissions->pay($this->byCode(Commission::class, $code), $request->all(), $this->actor($request)))]);
    }
}
