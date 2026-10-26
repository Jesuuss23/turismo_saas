<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Exceptions\ApiException;
use App\Models\Catalog\ConfigGroup;
use App\Models\Catalog\Departure;
use App\Models\Catalog\Hold;
use App\Models\Catalog\ItineraryDay;
use App\Models\Catalog\ItineraryItem;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductB2bRate;
use App\Models\Catalog\ProductChannel;
use App\Models\Catalog\ProductFaq;
use App\Models\Catalog\ProductInclusion;
use App\Models\Catalog\ProductItem;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductPromotion;
use App\Models\Catalog\ProductRelated;
use App\Models\Catalog\ProductRequirement;
use App\Models\Catalog\ProductVersion;
use App\Models\Catalog\Provider;
use App\Models\Catalog\Rate;
use App\Services\Catalog\AvailabilityService;
use App\Services\Catalog\Links\CatalogLinks;
use App\Services\Catalog\PricingEngine;
use App\Services\Catalog\ProductParts;
use App\Services\Catalog\ProductService;
use App\Services\Catalog\ProductVersioning;
use App\Services\Catalog\RateService;
use App\Services\Catalog\SeasonService;
use App\Services\Crm\CrmClock;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Spec 02 §6.2: pestañas y sub-recursos del Product 360. */
class ProductTabsController extends CatalogController
{
    // ── Componentes ───────────────────────────────────────────────

    public function components(Request $request, string $ref, TenantContext $tenant): JsonResponse
    {
        $product = $this->product($ref);
        $user = $this->actor($request);
        $fx = $tenant->tenant()?->fxToPen('USD') ?? 1.0;
        $rows = $product->items()->with(['service.provider', 'componentProduct'])->get()->map(fn ($i) => $this->present->component($i, $user, $fx))->all();
        $meta = ['fx_usd' => $fx];
        if ($this->access->seeCosts($user)) {
            $meta['total_cost_pen'] = round(collect($rows)->sum(fn ($r) => $r['total_cost_pen'] ?? 0), 2);
        }

        return $this->ok($rows, $meta);
    }

    public function addComponent(Request $request, string $ref, ProductParts $parts): JsonResponse
    {
        $data = $request->validate([
            'service_id' => 'nullable|uuid|required_without:component_product_id', 'component_product_id' => 'nullable|uuid',
            'quantity' => 'nullable|numeric|min:1', 'unit' => 'nullable|in:pax,servicio,habitación,noche,vehículo,unidad',
            'is_required' => 'nullable|boolean', 'is_included' => 'nullable|boolean', 'unit_cost_override' => 'nullable|numeric|min:0', 'notes' => 'nullable|string|max:500',
        ]);
        $item = $parts->addComponent($this->product($ref), $data, $this->actor($request));

        return $this->created(['id' => $item->id]);
    }

    public function updateComponent(Request $request, string $ref, string $component, ProductParts $parts): JsonResponse
    {
        $product = $this->product($ref);
        $data = $request->validate(['quantity' => 'sometimes|numeric|min:1', 'unit' => 'sometimes|in:pax,servicio,habitación,noche,vehículo,unidad',
            'is_required' => 'sometimes|boolean', 'is_included' => 'sometimes|boolean', 'unit_cost_override' => 'sometimes|nullable|numeric|min:0', 'notes' => 'sometimes|nullable|string|max:500']);
        $parts->updateComponent($product, $this->child(ProductItem::class, $component, 'product_id', $product->id), $data, $this->actor($request));

        return $this->ok(['id' => $component]);
    }

    public function removeComponent(Request $request, string $ref, string $component, ProductParts $parts): JsonResponse
    {
        $product = $this->product($ref);
        $parts->removeComponent($product, $this->child(ProductItem::class, $component, 'product_id', $product->id), $this->actor($request));

        return $this->ok(['deleted' => true]);
    }

    public function reorderComponents(Request $request, string $ref, ProductParts $parts): JsonResponse
    {
        $data = $request->validate(['ids' => 'required|array', 'ids.*' => 'uuid']);
        $parts->reorderComponents($this->product($ref), $data['ids'], $this->actor($request));

        return $this->ok(['reordered' => true]);
    }

    // ── Itinerario ───────────────────────────────────────────────

    public function itinerary(Request $request, string $ref, ProductParts $parts): JsonResponse
    {
        $product = $this->product($ref);
        $itinerary = $parts->itinerary($product)->load('days.items');
        $user = $this->actor($request);

        return $this->ok([
            'id' => $itinerary->id,
            'name' => $itinerary->name,
            'requires_itinerary' => (bool) $product->type?->requires_itinerary,
            'days' => $itinerary->days->map(fn ($d) => $this->present->day($d, $user))->all(),
            'services' => $product->items()->whereNotNull('service_id')->with('service:id,ref,name')->get()->map(fn ($i) => ['id' => $i->service_id, 'ref' => $i->service?->ref, 'name' => $i->service?->name])->unique('id')->values()->all(),
        ]);
    }

    public function addDay(Request $request, string $ref, ProductParts $parts): JsonResponse
    {
        $data = $request->validate(['title' => 'required|string|max:160']);
        $day = $parts->addDay($this->product($ref), $data, $this->actor($request));

        return $this->created(['id' => $day->id, 'day_number' => $day->day_number]);
    }

    public function updateDay(Request $request, string $ref, string $day, ProductParts $parts): JsonResponse
    {
        $product = $this->product($ref);
        $data = $request->validate(['title' => 'required|string|max:160']);
        $parts->updateDay($product, $this->day($product, $parts, $day), $data, $this->actor($request));

        return $this->ok(['id' => $day]);
    }

    public function removeDay(Request $request, string $ref, string $day, ProductParts $parts): JsonResponse
    {
        $product = $this->product($ref);
        $parts->removeDay($product, $this->day($product, $parts, $day), $this->actor($request));

        return $this->ok(['deleted' => true]);
    }

    public function addItem(Request $request, string $ref, string $day, ProductParts $parts): JsonResponse
    {
        $product = $this->product($ref);
        $item = $parts->addItem($product, $this->day($product, $parts, $day), $this->itemRules($request), $this->actor($request));

        return $this->created(['id' => $item->id]);
    }

    public function updateItem(Request $request, string $ref, string $day, string $item, ProductParts $parts): JsonResponse
    {
        $product = $this->product($ref);
        $dayModel = $this->day($product, $parts, $day);
        $parts->updateItem($product, $dayModel, $this->child(ItineraryItem::class, $item, 'day_id', $dayModel->id), $this->itemRules($request), $this->actor($request));

        return $this->ok(['id' => $item]);
    }

    public function removeItem(Request $request, string $ref, string $day, string $item, ProductParts $parts): JsonResponse
    {
        $product = $this->product($ref);
        $dayModel = $this->day($product, $parts, $day);
        $parts->removeItem($product, $dayModel, $this->child(ItineraryItem::class, $item, 'day_id', $dayModel->id), $this->actor($request));

        return $this->ok(['deleted' => true]);
    }

    private function day(Product $product, ProductParts $parts, string $id): ItineraryDay
    {
        return $this->child(ItineraryDay::class, $id, 'itinerary_id', $parts->itinerary($product)->id);
    }

    private function itemRules(Request $request): array
    {
        return $request->validate([
            'activity' => 'required|string|max:200', 'start_time' => 'nullable|date_format:H:i', 'item_type' => ['nullable', Rule::in(ProductParts::ITEM_TYPES)],
            'location' => 'nullable|string|max:160', 'service_id' => 'nullable|uuid', 'note' => 'nullable|string|max:1000', 'image_ref' => 'nullable|string|max:300',
            'cost' => 'nullable|numeric|min:0', 'price' => 'nullable|numeric|min:0',
        ]);
    }

    // ── Tarifas del producto ──────────────────────────────────────

    public function rates(Request $request, string $ref, RateService $rates): JsonResponse
    {
        $product = $this->product($ref);
        $user = $this->actor($request);
        $visible = $rates->visible($product, $user);
        $from = $rates->fromPrice($visible);

        return $this->ok($visible->map(fn (Rate $r) => $this->present->rate($r, $user))->values()->all(), [
            'from_price' => $from?->ref,
            'hidden_net' => ! $this->access->seeNetRates($user),
        ]);
    }

    public function storeRate(Request $request, string $ref, RateService $rates): JsonResponse
    {
        $data = $request->validate(ProductController::rateRules());
        $result = $rates->create($this->product($ref), $data, $this->actor($request));

        return $this->created($this->present->rate($result['rate']->load('season'), $this->actor($request)), $result['warnings']);
    }

    public function changePrice(Request $request, string $ref, string $rate, RateService $rates): JsonResponse
    {
        $product = $this->product($ref);
        $model = $this->child(Rate::class, $rate, 'product_id', $product->id);
        $this->ifMatch($request, $model);
        $data = $request->validate(['cost' => 'required|numeric|min:0', 'price' => 'required|numeric|gt:0', 'reason' => 'required|string|max:500']);
        $result = $rates->changePrice($model, $data, $this->actor($request));

        return $this->ok($this->present->rate($result['rate']->load('season'), $this->actor($request)), $result['warnings'] ? ['warnings' => $result['warnings']] : []);
    }

    public function rateStatus(Request $request, string $ref, string $rate, string $action, RateService $rates): JsonResponse
    {
        $product = $this->product($ref);
        $model = $rates->setStatus($this->child(Rate::class, $rate, 'product_id', $product->id), $action === 'activate', $this->actor($request));

        return $this->ok($this->present->rate($model->load('season'), $this->actor($request)));
    }

    /** CAT-BR-15: tarifa aplicable + motivos de descarte (solo tarifas visibles al rol). */
    public function resolveRate(Request $request, string $ref, RateService $rates, SeasonService $seasons, CrmClock $clock): JsonResponse
    {
        $data = $request->validate(['date' => 'nullable|date_format:Y-m-d', 'segment' => ['nullable', Rule::in(Rate::SEGMENTS)], 'channel' => ['nullable', Rule::in(Rate::CHANNELS)]]);
        $product = $this->product($ref);
        $user = $this->actor($request);
        $date = $data['date'] ?? $clock->today()->toDateString();
        $season = $seasons->resolve($date);
        $result = $rates->resolve($rates->visible($product, $user), $date, $data['segment'] ?? null, $data['channel'] ?? null, $season);

        return $this->ok([
            'applicable' => $result['applicable'] ? $this->present->rate($result['applicable'], $user) : null,
            'season' => $season ? $this->present->season($season) : null,
            'rows' => array_map(fn ($row) => ['rate' => $this->present->rate($row['rate'], $user), 'selectable' => $row['selectable'], 'reason' => $row['reason']], $result['rows']),
        ], ['date' => $date]);
    }

    // ── Temporadas asociadas ─────────────────────────────────────

    public function seasons(Request $request, string $ref): JsonResponse
    {
        $product = $this->product($ref);
        $usedByRates = Rate::where('product_id', $product->id)->whereNotNull('season_id')->pluck('season_id')->unique()->all();
        $rows = $product->seasons()->with('season')->get()->filter(fn ($ps) => $ps->season)->map(fn ($ps) => $this->present->season($ps->season, [
            'used_by_rates' => in_array($ps->season_id, $usedByRates, true),
        ]))->sortBy('date_from')->values()->all();

        return $this->ok($rows);
    }

    public function associateSeason(Request $request, string $ref, ProductService $products): JsonResponse
    {
        $data = $request->validate(['season_id' => 'required|uuid']);
        $products->associateSeason($this->product($ref), $data['season_id'], $this->actor($request));

        return $this->created(['season_id' => $data['season_id']]);
    }

    public function dissociateSeason(Request $request, string $ref, string $season, ProductService $products): JsonResponse
    {
        $products->dissociateSeason($this->product($ref), $season, $this->actor($request));

        return $this->ok(['deleted' => true]);
    }

    // ── Disponibilidad y salidas ──────────────────────────────────

    public function availability(Request $request, string $ref, AvailabilityService $availability, CrmClock $clock): JsonResponse
    {
        $product = $this->product($ref);
        $departures = Departure::where('product_id', $product->id)->whereDate('departure_date', '>=', $clock->today()->toDateString())->get();
        $held = $availability->heldMany($departures->pluck('id'));

        return $this->ok([
            'availability_model' => $product->availability_model,
            'availability_capacity' => $product->availability_capacity,
            'availability_source' => $product->availability_source,
            'external_notice' => $product->availability_source === 'externa'
                ? 'Disponibilidad externa informativa: adaptador INT-06 parcial, confirme con el proveedor (PRD-BR-013).' : null,
            'future_departures' => $departures->count(),
            'capacity' => $departures->whereIn('status', ['abierta', 'completa'])->sum('capacity'),
            'sold' => $departures->sum('sold'),
            'held' => array_sum($held),
            'available' => $departures->sum(fn ($d) => $availability->available($d, $held[$d->id] ?? 0)),
            'active_holds' => Hold::whereIn('departure_id', $departures->pluck('id'))->where('status', 'hold')->count(),
            'models' => Product::AVAILABILITY_MODELS,
        ]);
    }

    public function setAvailability(Request $request, string $ref, ProductService $products): JsonResponse
    {
        $data = $request->validate(['availability_model' => ['required', Rule::in(Product::AVAILABILITY_MODELS)],
            'availability_capacity' => 'nullable|integer|min:0', 'availability_source' => 'required|in:interna,externa']);
        $products->setAvailability($this->product($ref), $data, $this->actor($request));

        return $this->ok($data);
    }

    public function departures(Request $request, string $ref, AvailabilityService $availability): JsonResponse
    {
        $product = $this->product($ref);
        $rows = Departure::where('product_id', $product->id)->with('provider:id,name')->orderBy('departure_date')->orderBy('departure_time')->get();
        $held = $availability->heldMany($rows->pluck('id'));

        return $this->ok($rows->map(fn ($d) => $this->present->departure($d, $held[$d->id] ?? 0, $availability->available($d, $held[$d->id] ?? 0)))->all());
    }

    public function storeDeparture(Request $request, string $ref, AvailabilityService $availability): JsonResponse
    {
        $result = $availability->createDeparture($this->product($ref), DepartureController::rules($request), $this->actor($request));

        return $this->created(['id' => $result['departure']->id, 'ref' => $result['departure']->ref], $result['warnings']);
    }

    public function updateDeparture(Request $request, string $ref, string $departure, AvailabilityService $availability): JsonResponse
    {
        $product = $this->product($ref);
        $model = $this->child(Departure::class, $departure, 'product_id', $product->id);
        $this->ifMatch($request, $model);
        $result = $availability->updateDeparture($model, DepartureController::rules($request, true), $this->actor($request));

        return $this->ok(['id' => $result['departure']->id, 'status' => $result['departure']->status], $result['warnings'] ? ['warnings' => $result['warnings']] : []);
    }

    // ── Proveedores involucrados ─────────────────────────────────

    public function providers(Request $request, string $ref): JsonResponse
    {
        $product = $this->product($ref);
        $this->access->ensure($this->actor($request), [$this->access::PROFIT, $this->access::AVAIL, $this->access::PROVIDERS, $this->access::UPDATE, $this->access::RATES]);
        $user = $this->actor($request);
        $items = $product->items()->with('service.provider')->get();
        $ids = $items->pluck('service.provider_id')->filter()->push($product->main_provider_id)->filter()->unique();
        $rows = Provider::withTrashed()->whereIn('id', $ids)->orderBy('name')->get()->map(fn (Provider $p) => $this->present->provider($p, $user, [
            'is_main' => $p->id === $product->main_provider_id,
            'services' => $items->filter(fn ($i) => $i->service?->provider_id === $p->id)->map(fn ($i) => ['ref' => $i->service->ref, 'name' => $i->service->name])->values()->all(),
        ]))->all();

        return $this->ok($rows);
    }

    public function setMainProvider(Request $request, string $ref, ProductService $products): JsonResponse
    {
        $data = $request->validate(['provider_id' => 'required|uuid', 'reason' => 'required|string|max:500']);
        $products->setMainProvider($this->product($ref), $data['provider_id'], $data['reason'], $this->actor($request));

        return $this->ok(['provider_id' => $data['provider_id']]);
    }

    // ── Políticas, inclusiones, requisitos, relacionados ─────────────

    public function policies(string $ref): JsonResponse
    {
        $policy = $this->product($ref)->policies;

        return $this->ok($policy ? $policy->only(['cancellation', 'modification', 'no_show', 'penalties', 'refund', 'date_change', 'advance_min', 'advance_max']) : array_fill_keys(array_keys(ProductService::DEFAULT_POLICIES), null));
    }

    public function setPolicies(Request $request, string $ref, ProductParts $parts): JsonResponse
    {
        $data = $request->validate(['cancellation' => 'required|string|max:5000', 'modification' => 'required|string|max:5000', 'no_show' => 'required|string|max:5000',
            'refund' => 'required|string|max:5000', 'penalties' => 'nullable|string|max:5000', 'date_change' => 'nullable|string|max:5000',
            'advance_min' => 'nullable|string|max:40', 'advance_max' => 'nullable|string|max:40']);
        $policy = $parts->setPolicies($this->product($ref), $data, $this->actor($request));

        return $this->ok($policy->only(array_keys($data)));
    }

    public function inclusions(string $ref): JsonResponse
    {
        $rows = $this->product($ref)->inclusions;

        return $this->ok([
            'include' => $rows->where('kind', 'include')->map(fn ($r) => ['id' => $r->id, 'text' => $r->text])->values()->all(),
            'exclude' => $rows->where('kind', 'exclude')->map(fn ($r) => ['id' => $r->id, 'text' => $r->text])->values()->all(),
        ]);
    }

    public function addInclusion(Request $request, string $ref, ProductParts $parts): JsonResponse
    {
        $data = $request->validate(['kind' => 'required|in:include,exclude', 'text' => 'required|string|max:200']);
        $row = $parts->addInclusion($this->product($ref), $data['kind'], $data['text'], $this->actor($request));

        return $this->created(['id' => $row->id, 'text' => $row->text]);
    }

    public function removeInclusion(Request $request, string $ref, string $inclusion, ProductParts $parts): JsonResponse
    {
        $product = $this->product($ref);
        $parts->removeInclusion($product, $this->child(ProductInclusion::class, $inclusion, 'product_id', $product->id), $this->actor($request));

        return $this->ok(['deleted' => true]);
    }

    public function requirements(string $ref): JsonResponse
    {
        return $this->ok($this->product($ref)->requirements->map(fn ($r) => ['id' => $r->id, 'category' => $r->category, 'text' => $r->text])->all(),
            ['categories' => ProductParts::REQUIREMENT_CATEGORIES]);
    }

    public function addRequirement(Request $request, string $ref, ProductParts $parts): JsonResponse
    {
        $data = $request->validate(['category' => ['nullable', Rule::in(ProductParts::REQUIREMENT_CATEGORIES)], 'text' => 'required|string|max:300']);
        $row = $parts->addRequirement($this->product($ref), $data, $this->actor($request));

        return $this->created(['id' => $row->id]);
    }

    public function removeRequirement(Request $request, string $ref, string $requirement, ProductParts $parts): JsonResponse
    {
        $product = $this->product($ref);
        $parts->removeRequirement($product, $this->child(ProductRequirement::class, $requirement, 'product_id', $product->id), $this->actor($request));

        return $this->ok(['deleted' => true]);
    }

    public function related(string $ref): JsonResponse
    {
        $product = $this->product($ref);
        $map = fn (?Product $p) => $p ? ['id' => $p->id, 'ref' => $p->ref, 'name' => $p->name, 'status' => $p->status, 'sellable' => $p->isSellable()] : null;

        return $this->ok([
            'related' => ProductRelated::where('product_id', $product->id)->with('related')->get()->map(fn ($r) => ['id' => $r->id, 'relation' => $r->relation, 'product' => $map($r->related)])->all(),
            'referenced_by' => ProductRelated::where('related_product_id', $product->id)->with('product')->get()->map(fn ($r) => ['id' => $r->id, 'relation' => $r->relation, 'product' => $map($r->product)])->all(),
        ], ['relations' => ProductParts::RELATIONS]);
    }

    public function addRelated(Request $request, string $ref, ProductParts $parts): JsonResponse
    {
        $data = $request->validate(['related_product_id' => 'required|uuid', 'relation' => ['required', Rule::in(ProductParts::RELATIONS)]]);
        $row = $parts->relate($this->product($ref), $data['related_product_id'], $data['relation'], $this->actor($request));

        return $this->created(['id' => $row->id]);
    }

    public function removeRelated(Request $request, string $ref, string $related, ProductParts $parts): JsonResponse
    {
        $product = $this->product($ref);
        $parts->unrelate($product, $this->child(ProductRelated::class, $related, 'product_id', $product->id), $this->actor($request));

        return $this->ok(['deleted' => true]);
    }

    // ── B2B ──────────────────────────────────────────────────────

    public function b2bRates(Request $request, string $ref, CatalogLinks $links): JsonResponse
    {
        $user = $this->actor($request);
        if (! $this->access->seeNetRates($user)) {
            $this->access->ensure($user, [$this->access::PROFIT, $this->access::RATES, 'channels.b2b.manage']);
        }
        $product = $this->product($ref);
        $agencies = collect($links->agencies())->keyBy('id');

        return $this->ok(ProductB2bRate::where('product_id', $product->id)->get()->map(fn (ProductB2bRate $b) => [
            'id' => $b->id,
            'agency' => $agencies[$b->agency_id] ?? ['id' => $b->agency_id, 'name' => 'Agencia no disponible', 'status' => null],
            'price_list' => $b->price_list, 'price' => (string) $b->price, 'currency' => $b->currency, 'commission_pct' => (string) $b->commission_pct,
            'is_credit' => (bool) $b->is_credit, 'valid_to' => $b->valid_to?->toDateString(), 'conditions' => $b->conditions, 'restrictions' => $b->restrictions,
            'expired' => $b->valid_to && $b->valid_to->toDateString() < now()->toDateString(),
        ])->all(), ['agencies' => $agencies->values()->all(), 'b2b_published' => $product->channels()->where('channel', 'b2b')->where('is_published', true)->exists(), 'is_b2b' => $product->is_b2b]);
    }

    public function saveB2bRate(Request $request, string $ref, ProductParts $parts, ?string $b2b = null): JsonResponse
    {
        $product = $this->product($ref);
        $current = $b2b ? $this->child(ProductB2bRate::class, $b2b, 'product_id', $product->id) : null;
        $data = $request->validate([
            'agency_id' => $current ? 'prohibited' : 'required|uuid', 'price_list' => 'required|string|max:80', 'price' => 'required|numeric|gt:0',
            'commission_pct' => 'nullable|numeric|min:0|max:100', 'is_credit' => 'nullable|boolean', 'valid_to' => 'required|date_format:Y-m-d',
            'conditions' => 'nullable|string|max:200', 'restrictions' => 'nullable|string|max:200',
        ]);
        $result = $parts->saveB2bRate($product, $data, $this->actor($request), $current);

        return $current ? $this->ok(['id' => $result['rate']->id], $result['warnings'] ? ['warnings' => $result['warnings']] : []) : $this->created(['id' => $result['rate']->id], $result['warnings']);
    }

    public function removeB2bRate(Request $request, string $ref, string $b2b, ProductParts $parts): JsonResponse
    {
        $product = $this->product($ref);
        $parts->removeB2bRate($product, $this->child(ProductB2bRate::class, $b2b, 'product_id', $product->id), $this->actor($request));

        return $this->ok(['deleted' => true]);
    }

    // ── Promociones ──────────────────────────────────────────────

    public function promotions(Request $request, string $ref, RateService $rates): JsonResponse
    {
        $product = $this->product($ref);
        $public = $rates->fromPrice($rates->visible($product, $this->actor($request)));
        $rows = $product->promotions->map(fn (ProductPromotion $p) => $this->present->promotion($p) + [
            'resulting_price' => $public ? $this->promoPrice($p, (float) $public->price) : null,
        ])->all();

        return $this->ok($rows, ['public_rate' => $public ? ['ref' => $public->ref, 'currency' => $public->currency, 'price' => (string) $public->price] : null,
            'kinds' => ProductPromotion::KINDS, 'channels' => ProductParts::PROMO_CHANNELS]);
    }

    private function promoPrice(ProductPromotion $p, float $base): string
    {
        $value = (float) $p->value;
        $price = match (true) {
            $p->kind === 'Precio promocional' => $value,
            $p->kind === 'Descuento fijo' => $base - $value,
            default => $base * (1 - $value / 100),
        };

        return number_format(max(0, $price), 2, '.', '');
    }

    public function addPromotion(Request $request, string $ref, ProductParts $parts): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'kind' => ['required', Rule::in(ProductPromotion::KINDS)], 'value' => 'required|numeric',
            'channel' => ['nullable', Rule::in(ProductParts::PROMO_CHANNELS)], 'promo_code' => 'nullable|string|max:30|alpha_dash',
            'date_from' => 'required|date_format:Y-m-d', 'date_to' => 'required|date_format:Y-m-d']);
        $promo = $parts->addPromotion($this->product($ref), $data, $this->actor($request));

        return $this->created($this->present->promotion($promo));
    }

    public function promotionStatus(Request $request, string $ref, string $promotion, string $action, ProductParts $parts): JsonResponse
    {
        $product = $this->product($ref);
        $promo = $parts->setPromotionStatus($product, $this->child(ProductPromotion::class, $promotion, 'product_id', $product->id), $action === 'activate', $this->actor($request));

        return $this->ok($this->present->promotion($promo));
    }

    public function removePromotion(Request $request, string $ref, string $promotion, ProductParts $parts): JsonResponse
    {
        $product = $this->product($ref);
        $parts->removePromotion($product, $this->child(ProductPromotion::class, $promotion, 'product_id', $product->id), $this->actor($request));

        return $this->ok(['deleted' => true]);
    }

    // ── Rentabilidad, markup, personalización ────────────────────────

    public function profitability(Request $request, string $ref, PricingEngine $engine, RateService $rates, TenantContext $tenant): JsonResponse
    {
        $data = $request->validate(['rate_id' => 'nullable|uuid', 'pax' => 'nullable|integer|min:1|max:999', 'other_cost' => 'nullable|numeric|min:0', 'commission_pct' => 'nullable|numeric|min:0|max:100']);
        $product = $this->product($ref);
        $user = $this->actor($request);
        $all = $product->rates()->with('season')->get();
        $rate = isset($data['rate_id']) ? $all->firstWhere('id', $data['rate_id']) : $rates->fromPrice($all);
        $fx = $tenant->tenant()?->fxToPen('USD') ?? 1.0;
        $componentsPen = $product->items()->with('service')->get()->sum(function (ProductItem $i) use ($fx) {
            $cost = $i->unit_cost_override ?? $i->service?->cost ?? 0;

            return (float) $cost * (float) $i->quantity * ($i->service?->currency === 'USD' ? $fx : 1);
        });

        return $this->ok([
            'breakdown' => $rate ? $engine->profitability($rate, (int) ($data['pax'] ?? 2), (float) ($data['other_cost'] ?? 0), (float) ($data['commission_pct'] ?? 10)) : null,
            'by_rate' => $all->map(fn (Rate $r) => $this->present->rate($r, $user))->values()->all(),
            'components_cost_pen' => round($componentsPen, 2),
            'markup_pct' => (string) $product->markup_pct,
            'can_edit_markup' => $this->access->can($user, $this->access::RATES),
        ]);
    }

    public function setMarkup(Request $request, string $ref, ProductService $products): JsonResponse
    {
        $data = $request->validate(['markup_pct' => 'required|numeric|min:0|max:500']);
        $product = $products->setMarkup($this->product($ref), (float) $data['markup_pct'], $this->actor($request));

        return $this->ok(['markup_pct' => (string) $product->markup_pct]);
    }

    public function configGroups(string $ref): JsonResponse
    {
        return $this->ok($this->product($ref)->configGroups()->with(['options' => fn ($q) => $q->orderBy('sort_order')])->get()->map(fn (ConfigGroup $g) => [
            'id' => $g->id, 'name' => $g->name,
            'options' => $g->options->map(fn ($o) => ['id' => $o->id, 'label' => $o->label, 'price_delta' => (string) $o->price_delta])->all(),
        ])->all());
    }

    public function addConfigGroup(Request $request, string $ref, ProductParts $parts): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:60', 'options' => 'required|array|min:2', 'options.*.label' => 'required|string|max:80', 'options.*.price_delta' => 'nullable|numeric']);
        $group = $parts->addConfigGroup($this->product($ref), $data['name'], $data['options'], $this->actor($request));

        return $this->created(['id' => $group->id]);
    }

    public function removeConfigGroup(Request $request, string $ref, string $group, ProductParts $parts): JsonResponse
    {
        $product = $this->product($ref);
        $parts->removeConfigGroup($product, $this->child(ConfigGroup::class, $group, 'product_id', $product->id), $this->actor($request));

        return $this->ok(['deleted' => true]);
    }

    /** PRD-T15: precio recalculado en backend; cualquier precio enviado se ignora. */
    public function priceQuote(Request $request, string $ref, PricingEngine $engine, RateService $rates, CrmClock $clock): JsonResponse
    {
        $data = $request->validate(['rate_id' => 'required|uuid', 'selections' => 'nullable|array', 'date' => 'nullable|date_format:Y-m-d', 'pax' => 'nullable|integer|min:1|max:999']);
        $product = $this->product($ref);
        $rate = $rates->visible($product, $this->actor($request))->firstWhere('id', $data['rate_id']) ?? throw ApiException::invalid('rate_id', 'Tarifa inexistente o no visible.');

        return $this->ok($engine->priceQuote($product, $rate, $data['selections'] ?? [], $data['date'] ?? $clock->today()->toDateString(), (int) ($data['pax'] ?? 1)));
    }

    // ── Contenido, multimedia, FAQ ──────────────────────────────────

    public function content(string $ref): JsonResponse
    {
        $product = $this->product($ref);

        return $this->ok($product->only(ProductService::CONTENT) + [
            'features' => $product->features ?? [],
            'media' => $product->media->map(fn (ProductMedia $m) => ['id' => $m->id, 'storage_path' => $m->storage_path, 'alt_text' => $m->alt_text, 'is_main' => $m->is_main])->all(),
            'faqs' => $product->faqs->map(fn (ProductFaq $f) => ['id' => $f->id, 'question' => $f->question, 'answer' => $f->answer])->all(),
        ]);
    }

    public function setContent(Request $request, string $ref, ProductService $products): JsonResponse
    {
        $data = $request->validate(['short_description' => 'nullable|string|max:2000', 'long_description' => 'nullable|string|max:20000', 'features' => 'nullable|array',
            'features.*' => 'string|max:120', 'video_url' => 'nullable|url|max:500', 'seo_title' => 'nullable|string|max:120', 'seo_description' => 'nullable|string|max:320', 'slug' => 'nullable|string|max:160']);
        $product = $products->setContent($this->product($ref), $data, $this->actor($request));

        return $this->ok($product->only(ProductService::CONTENT));
    }

    public function addMedia(Request $request, string $ref, ProductParts $parts): JsonResponse
    {
        $data = $request->validate(['storage_path' => 'required|string|max:400', 'alt_text' => 'required|string|max:200', 'is_main' => 'nullable|boolean']);
        $media = $parts->addMedia($this->product($ref), $data, $this->actor($request));

        return $this->created(['id' => $media->id, 'is_main' => $media->is_main]);
    }

    public function updateMedia(Request $request, string $ref, string $media, ProductParts $parts): JsonResponse
    {
        $product = $this->product($ref);
        $request->validate(['is_main' => 'required|accepted']);
        $parts->setMainMedia($product, $this->child(ProductMedia::class, $media, 'product_id', $product->id), $this->actor($request));

        return $this->ok(['id' => $media, 'is_main' => true]);
    }

    public function removeMedia(Request $request, string $ref, string $media, ProductParts $parts): JsonResponse
    {
        $product = $this->product($ref);
        $parts->removeMedia($product, $this->child(ProductMedia::class, $media, 'product_id', $product->id), $this->actor($request));

        return $this->ok(['deleted' => true]);
    }

    public function addFaq(Request $request, string $ref, ProductParts $parts): JsonResponse
    {
        $data = $request->validate(['question' => 'required|string|max:300', 'answer' => 'required|string|max:5000']);
        $faq = $parts->addFaq($this->product($ref), $data, $this->actor($request));

        return $this->created(['id' => $faq->id]);
    }

    public function removeFaq(Request $request, string $ref, string $faq, ProductParts $parts): JsonResponse
    {
        $product = $this->product($ref);
        $parts->removeFaq($product, $this->child(ProductFaq::class, $faq, 'product_id', $product->id), $this->actor($request));

        return $this->ok(['deleted' => true]);
    }

    // ── Canales ──────────────────────────────────────────────────

    public function channels(string $ref): JsonResponse
    {
        $product = $this->product($ref);
        $rows = $product->channels->keyBy('channel');

        return $this->ok(collect(ProductChannel::CHANNELS)->map(function ($label, $channel) use ($rows, $product) {
            $row = $rows[$channel] ?? null;
            $published = (bool) $row?->is_published;

            return [
                'channel' => $channel, 'label' => $label, 'is_published' => $published, 'is_requested' => (bool) $row?->is_requested,
                'published_at' => $row?->published_at, 'published_by' => $this->present->users->user($row?->published_by),
                'pending_activation' => $published && $product->status !== Product::ACTIVE || (! $published && $row?->is_requested && $product->status !== Product::ACTIVE),
                'locked' => $channel === 'erp',
            ];
        })->values()->all(), ['product_active' => $product->status === Product::ACTIVE]);
    }

    public function setChannel(Request $request, string $ref, string $channel, ProductParts $parts): JsonResponse
    {
        $data = $request->validate(['published' => 'required|boolean']);
        $row = $parts->setChannel($this->product($ref), $channel, (bool) $data['published'], $this->actor($request));

        return $this->ok(['channel' => $row->channel, 'is_published' => $row->is_published]);
    }

    // ── Versiones ────────────────────────────────────────────────

    public function versions(string $ref): JsonResponse
    {
        $product = $this->product($ref);
        $rows = $product->versions()->get();
        $this->present->users->preloadUsers($rows->pluck('created_by'));

        return $this->ok($rows->map(fn (ProductVersion $v) => [
            'id' => $v->id, 'version' => $v->version, 'status' => $v->status, 'valid_from' => $v->valid_from->toDateString(), 'reason' => $v->reason,
            'created_at' => $v->created_at, 'created_by' => $this->present->users->user($v->created_by), 'is_current' => $v->id === $product->current_version_id,
        ])->all());
    }

    public function storeVersion(Request $request, string $ref, ProductVersioning $versions): JsonResponse
    {
        $data = $request->validate(['kind' => 'required|in:minor,major', 'valid_from' => 'required|date_format:Y-m-d', 'reason' => 'required|string|max:1000']);
        $version = $versions->create($this->product($ref), $data['kind'], $data['valid_from'], $data['reason'], $this->actor($request));

        return $this->created(['id' => $version->id, 'version' => $version->version, 'status' => $version->status]);
    }

    public function showVersion(string $ref, string $version): JsonResponse
    {
        $product = $this->product($ref);
        $v = $this->child(ProductVersion::class, $version, 'product_id', $product->id);

        return $this->ok(['id' => $v->id, 'version' => $v->version, 'status' => $v->status, 'valid_from' => $v->valid_from->toDateString(), 'reason' => $v->reason,
            'created_at' => $v->created_at, 'created_by' => $this->present->users->user($v->created_by), 'snapshot' => $v->snapshot]);
    }

    public function compareVersions(Request $request, string $ref): JsonResponse
    {
        $data = $request->validate(['a' => 'required|uuid', 'b' => 'required|uuid']);
        $product = $this->product($ref);
        $a = $this->child(ProductVersion::class, $data['a'], 'product_id', $product->id);
        $b = $this->child(ProductVersion::class, $data['b'], 'product_id', $product->id);

        return $this->ok(['a' => $a->version, 'b' => $b->version, 'diff' => ProductVersioning::compare($a->snapshot, $b->snapshot)]);
    }
}
