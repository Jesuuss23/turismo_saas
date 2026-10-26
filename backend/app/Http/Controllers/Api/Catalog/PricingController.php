<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Exceptions\ApiException;
use App\Models\Catalog\PriceRule;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductSeason;
use App\Models\Catalog\Rate;
use App\Models\Catalog\Season;
use App\Services\AuditLogger;
use App\Services\Catalog\PricingEngine;
use App\Services\Catalog\SeasonService;
use App\Services\Crm\CrmClock;
use App\Services\SequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Spec 02 §6.4: tarifas consolidadas, temporadas, reglas de pricing y simulador. */
class PricingController extends CatalogController
{
    public function rates(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $rows = Rate::query()->with(['product:id,ref,name,status', 'season:id,ref,name'])
            ->when($request->input('filter.type'), fn ($q, $v) => $q->where('rate_type', $v))
            ->when($request->input('filter.channel'), fn ($q, $v) => $q->where('channel', $v))
            ->when($request->input('filter.currency'), fn ($q, $v) => $q->where('currency', $v))
            ->orderBy('ref')->get()
            ->when(! $this->access->seeNetRates($user), fn ($c) => $c->reject(fn (Rate $r) => $r->isNet()))
            ->when($request->input('filter.status'), fn ($c, $s) => $c->filter(fn (Rate $r) => $r->effectiveStatus() === $s));

        return $this->ok($rows->map(fn (Rate $r) => $this->present->rate($r, $user) + ['product' => $r->product ? ['ref' => $r->product->ref, 'name' => $r->product->name, 'status' => $r->product->status] : null])->values()->all(), [
            'counts' => $rows->countBy(fn (Rate $r) => $r->effectiveStatus())->all(),
            'hidden_net' => ! $this->access->seeNetRates($user),
        ]);
    }

    public function seasons(SeasonService $seasons): JsonResponse
    {
        $rows = Season::orderBy('date_from')->orderBy('priority')->get();
        $rateUse = Rate::whereNotNull('season_id')->groupBy('season_id')->selectRaw('season_id, count(*) as n')->pluck('n', 'season_id');
        $productUse = ProductSeason::groupBy('season_id')->selectRaw('season_id, count(*) as n')->pluck('n', 'season_id');

        return $this->ok($rows->map(fn (Season $s) => $this->present->season($s, ['rates' => (int) ($rateUse[$s->id] ?? 0), 'products' => (int) ($productUse[$s->id] ?? 0)]))->all(), [
            'overlaps' => $seasons->overlaps(),
            'types' => Season::TYPES,
        ]);
    }

    public function storeSeason(Request $request, SeasonService $seasons): JsonResponse
    {
        $result = $seasons->create($this->seasonRules($request), $this->actor($request));

        return $this->created($this->present->season($result['season']), $result['warnings']);
    }

    public function updateSeason(Request $request, string $id, SeasonService $seasons): JsonResponse
    {
        $season = $this->find(Season::query(), $id);
        $result = $seasons->update($season, $this->seasonRules($request, true), $this->actor($request));

        return $this->ok($this->present->season($result['season']), $result['warnings'] ? ['warnings' => $result['warnings']] : []);
    }

    public function destroySeason(Request $request, string $id, SeasonService $seasons): JsonResponse
    {
        $seasons->delete($this->find(Season::query(), $id), $this->actor($request));

        return $this->ok(['deleted' => true]);
    }

    public function resolveSeason(Request $request, SeasonService $seasons, CrmClock $clock): JsonResponse
    {
        $date = $request->validate(['date' => 'nullable|date_format:Y-m-d'])['date'] ?? $clock->today()->toDateString();
        $matching = $seasons->matching($date);

        return $this->ok([
            'date' => $date,
            'applicable' => $matching->first() ? $this->present->season($matching->first()) : null,
            'others' => $matching->slice(1)->map(fn ($s) => $this->present->season($s))->values()->all(),
        ]);
    }

    private function seasonRules(Request $request, bool $editing = false): array
    {
        $req = $editing ? 'sometimes' : 'required';

        return $request->validate([
            'name' => "{$req}|string|max:120",
            'type' => [$req, Rule::in(Season::TYPES)],
            'date_from' => "{$req}|date_format:Y-m-d",
            'date_to' => "{$req}|date_format:Y-m-d",
            'priority' => "{$req}|integer|min:0|max:999",
            'factor' => "{$req}|numeric|min:0.01|max:3",
            'status' => 'sometimes|in:activa,inactiva',
        ]);
    }

    public function priceRules(): JsonResponse
    {
        return $this->ok(PriceRule::orderBy('priority')->orderBy('ref')->get()->map(fn (PriceRule $r) => $this->present->priceRule($r))->all(), ['kinds' => PriceRule::KINDS]);
    }

    public function storePriceRule(Request $request, SequenceService $sequences, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120', 'kind' => ['required', Rule::in(PriceRule::KINDS)], 'value' => 'required|numeric',
            'scope' => 'required|string|max:200', 'channel' => ['nullable', Rule::in(Rate::CHANNELS)], 'min_pax' => 'nullable|integer|min:1',
            'customer_residency' => 'nullable|in:dom,nodom', 'product_id' => 'nullable|uuid', 'currency' => 'nullable|in:PEN,USD', 'priority' => 'nullable|integer|min:0|max:999',
        ]);
        if (! empty($data['product_id']) && ! Product::whereKey($data['product_id'])->exists()) {
            throw ApiException::invalid('product_id', 'Producto inexistente.');
        }
        if (str_ends_with($data['kind'], '%') && ($data['value'] < 0 || $data['value'] > 100)) {
            throw ApiException::invalid('value', 'Los porcentajes deben estar entre 0 y 100.');
        }
        $rule = DB::transaction(function () use ($data, $sequences, $audit, $request) {
            $rule = PriceRule::create($data + ['ref' => $sequences->next('catalog_price_rule'), 'status' => 'activa', 'priority' => $data['priority'] ?? 100]);
            $audit->log('Regla de precio', $rule->ref, 'crear', null, "{$rule->name} · {$rule->kind} {$rule->value}", actor: $this->actor($request));

            return $rule;
        });

        return $this->created($this->present->priceRule($rule));
    }

    public function priceRuleStatus(Request $request, string $id, string $action, AuditLogger $audit): JsonResponse
    {
        $rule = $this->find(PriceRule::query(), $id);
        $target = $action === 'activate' ? 'activa' : 'inactiva';
        if ($rule->status !== $target) {
            DB::transaction(function () use ($rule, $target, $audit, $request) {
                $before = $rule->status;
                $rule->update(['status' => $target]);
                $audit->log('Regla de precio', $rule->ref, 'cambio_estado', $before, $target, actor: $this->actor($request));
            });
        }

        return $this->ok($this->present->priceRule($rule));
    }

    /** CAT-BR-42: desglose del motor de precios. */
    public function simulate(Request $request, PricingEngine $engine): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|string', 'rate_id' => 'nullable|uuid', 'date' => 'nullable|date_format:Y-m-d', 'pax' => 'nullable|integer|min:1|max:999',
            'channel' => ['nullable', Rule::in(Rate::CHANNELS)], 'residency' => 'nullable|in:dom,nodom', 'extra' => 'nullable|numeric|min:0',
            'discount_fixed' => 'nullable|numeric|min:0', 'markup_override' => 'nullable|numeric|min:0|max:500', 'discount_override' => 'nullable|numeric|min:0|max:100',
            'commission_pct' => 'nullable|numeric|min:0|max:100',
        ]);
        $product = $this->product($data['product_id']);
        $rates = $product->rates()->get();
        $rate = isset($data['rate_id']) ? $rates->firstWhere('id', $data['rate_id']) : $rates->first(fn (Rate $r) => $r->effectiveStatus() === 'vigente');
        if (! $rate) {
            throw ApiException::invalid('rate_id', 'El producto no tiene una tarifa vigente para simular.');
        }
        if (in_array($rate->effectiveStatus(), ['vencida', 'inactiva'], true)) {
            throw ApiException::unprocessable('PRD-BR-005', "La tarifa {$rate->ref} está {$rate->effectiveStatus()}: no se puede usar en el simulador.");
        }

        return $this->ok($engine->simulate($product, $rate, $data));
    }
}
