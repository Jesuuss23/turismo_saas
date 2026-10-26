<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Models\Catalog\Departure;
use App\Models\Catalog\Hold;
use App\Models\Catalog\Product;
use App\Models\Catalog\Rate;
use App\Models\Iam\User;
use App\Services\Catalog\Links\CatalogLinks;
use App\Services\Catalog\ProductLifecycle;
use App\Services\Catalog\ProductQuery;
use App\Services\Catalog\ProductService;
use App\Services\Catalog\RateService;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\CrmClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Spec 02 §6.1: productos y ciclo de vida. */
class ProductController extends CatalogController
{
    public function index(Request $request, ProductQuery $query): JsonResponse
    {
        $user = $this->actor($request);
        $perPage = min(200, max(1, (int) $request->input('per_page', 60)));
        $page = $query->query((array) $request->input('filter', []) + ['sort' => $request->input('sort')])->paginate($perPage);
        $items = collect($page->items());
        $prices = $query->fromPrices($items, $user);
        $departures = $query->futureDepartures($items->pluck('id'));
        $channels = $query->channelCounts($items->pluck('id'));

        return $this->ok($items->map(fn (Product $p) => $this->present->productRow($p, $user, $prices[$p->id] ?? null, [
            'future_departures' => $departures[$p->id] ?? 0,
            'channels_count' => $channels[$p->id] ?? 0,
            'main_image' => $p->media()->where('is_main', true)->value('storage_path'),
        ]))->all(), ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $perPage]);
    }

    public function kpis(Request $request, ProductQuery $query): JsonResponse
    {
        return $this->ok($query->kpis($this->actor($request)));
    }

    public function store(Request $request, ProductService $products): JsonResponse
    {
        $data = $request->validate($this->rules(true) + [
            'secondary_destination_ids' => 'nullable|array', 'secondary_destination_ids.*' => 'uuid',
            'main_provider_id' => 'nullable|uuid',
            'components' => 'nullable|array', 'components.*.service_id' => 'nullable|uuid', 'components.*.component_product_id' => 'nullable|uuid',
            'components.*.quantity' => 'nullable|numeric|min:1', 'components.*.unit' => 'nullable|string|in:pax,servicio,habitación,noche,vehículo,unidad',
            'components.*.is_required' => 'nullable|boolean', 'components.*.is_included' => 'nullable|boolean', 'components.*.notes' => 'nullable|string|max:500',
            'itinerary' => 'nullable|array', 'itinerary.*.title' => 'required|string|max:160', 'itinerary.*.items' => 'nullable|array',
            'itinerary.*.items.*.activity' => 'required|string|max:200', 'itinerary.*.items.*.start_time' => 'nullable|date_format:H:i',
            'itinerary.*.items.*.item_type' => 'nullable|string', 'itinerary.*.items.*.location' => 'nullable|string|max:160',
            'rates' => 'nullable|array', ...$this->rateRules('rates.*.'),
            'season_ids' => 'nullable|array', 'season_ids.*' => 'uuid',
            'availability_model' => ['nullable', Rule::in(Product::AVAILABILITY_MODELS)],
            'availability_capacity' => 'nullable|integer|min:0', 'availability_source' => 'nullable|in:interna,externa',
            'features' => 'nullable|array', 'features.*' => 'string|max:120', 'video_url' => 'nullable|url|max:500',
            'seo_title' => 'nullable|string|max:120', 'seo_description' => 'nullable|string|max:320', 'slug' => 'nullable|string|max:160',
            'channels' => 'nullable|array', 'channels.*' => 'string|in:erp,crm,quotes,b2b,portal,portal_pax,cms,ecommerce,mobile',
            'policies' => 'nullable|array', 'inclusions' => 'nullable|array', 'exclusions' => 'nullable|array',
            'requirements' => 'nullable|array', 'requirements.*.text' => 'required|string|max:300', 'requirements.*.category' => 'nullable|string',
            'markup_pct' => 'nullable|numeric|min:0|max:500',
        ]);
        $product = $products->create($data, $this->actor($request));

        return $this->show($request, $product->ref, app(ProductLifecycle::class), app(RateService::class), 201);
    }

    public function show(Request $request, string $ref, ProductLifecycle $lifecycle, RateService $rates, int $status = 200): JsonResponse
    {
        $user = $this->actor($request);
        $product = $this->product($ref)->load(['type', 'category', 'subcategory', 'primaryDestination', 'mainProvider', 'currentVersion', 'secondaryDestinations.destination']);
        $visible = $rates->visible($product, $user);
        $today = app(CrmClock::class)->today()->toDateString();
        $departures = Departure::where('product_id', $product->id)->get();
        $future = $departures->filter(fn ($d) => $d->departure_date->toDateString() >= $today);
        $openHolds = Hold::whereIn('departure_id', $departures->pluck('id'))->where('status', 'hold')->where('expires_at', '>', now())->sum('quantity');

        return response()->json(['data' => $this->present->productDetail($product, $user, $rates->fromPrice($visible), [
            'secondary_destinations' => $product->secondaryDestinations->map(fn ($d) => ['id' => $d->destination_id, 'name' => $d->destination?->name])->values()->all(),
            'checklist_summary' => $lifecycle->summary($product),
            'indicators' => [
                'rates_current' => $visible->filter(fn (Rate $r) => $r->effectiveStatus() === 'vigente')->count(),
                'components' => $product->items()->count(),
                'future_departures' => $future->whereIn('status', ['abierta', 'completa'])->count(),
                'seats_available' => $future->where('status', 'abierta')->sum(fn ($d) => max(0, $d->capacity - $d->sold)) - (int) $openHolds,
                'channels_published' => $product->channels()->where('is_published', true)->count(),
                'versions' => $product->versions()->count(),
            ],
            'tabs' => $this->access->tabsFor($user),
            'actions' => $this->access->actions($user),
        ])], $status);
    }

    public function update(Request $request, string $ref, ProductService $products, ProductLifecycle $lifecycle, RateService $rates): JsonResponse
    {
        $product = $this->product($ref);
        $this->ifMatch($request, $product);
        $data = $request->validate($this->rules(false) + ['main_provider_id' => 'sometimes|nullable|uuid', 'reason' => 'nullable|string|max:500',
            'secondary_destination_ids' => 'sometimes|array', 'secondary_destination_ids.*' => 'uuid']);
        $user = $this->actor($request);
        $products->update($product, $data, $user, $data['reason'] ?? null);
        if (array_key_exists('secondary_destination_ids', $data)) {
            $products->syncSecondary($product, $data['secondary_destination_ids'], $user);
        }

        return $this->show($request, $product->ref, $lifecycle, $rates);
    }

    public function destroy(Request $request, string $ref, ProductLifecycle $lifecycle): JsonResponse
    {
        $product = $this->product($ref);
        $lifecycle->delete($product, $request->input('reason'), $this->actor($request));

        return $this->ok(['ref' => $product->ref, 'deleted' => true]);
    }

    public function references(string $ref, ProductLifecycle $lifecycle): JsonResponse
    {
        return $this->ok($lifecycle->references($this->product($ref)));
    }

    public function duplicate(Request $request, string $ref, ProductService $products): JsonResponse
    {
        $copy = $products->duplicate($this->product($ref), $this->actor($request));

        return $this->created(['id' => $copy->id, 'ref' => $copy->ref, 'code' => $copy->code, 'name' => $copy->name, 'status' => $copy->status]);
    }

    public function checklist(string $ref, ProductLifecycle $lifecycle): JsonResponse
    {
        $rows = $lifecycle->checklist($this->product($ref));

        return $this->ok($rows, ['ok' => count(array_filter($rows, fn ($r) => $r['ok'])), 'total' => count($rows)]);
    }

    /** Transiciones §4.1: submit-review, approve, return-to-draft, activate, suspend, deactivate, archive. */
    public function transition(Request $request, string $ref, string $action, ProductLifecycle $lifecycle, RateService $rates): JsonResponse
    {
        $product = $this->product($ref);
        $this->ifMatch($request, $product);
        $user = $this->actor($request);
        $reason = $request->input('reason');
        match ($action) {
            'submit-review' => $lifecycle->submitReview($product, $user),
            'approve' => $lifecycle->approve($product, $user),
            'return-to-draft' => $lifecycle->returnToDraft($product, $reason, $user),
            'activate' => $lifecycle->activate($product, $user),
            'suspend' => $lifecycle->suspend($product, $reason, $user),
            'deactivate' => $lifecycle->deactivate($product, $reason, $user),
            'archive' => $lifecycle->archive($product, $reason, $user),
        };

        return $this->show($request, $product->ref, $lifecycle, $rates);
    }

    /** Uso en ventas; Ventas ve solo lo propio ("Uso en mi cartera", §7.2). */
    public function usage(Request $request, string $ref, CatalogLinks $links, CrmAccess $crm): JsonResponse
    {
        $product = $this->product($ref);
        /** @var User $user */
        $user = $this->actor($request);
        $own = $crm->ownOnly($user);

        return $this->ok($links->productUsage($product->id, $own ? $user->id : null), ['own_only' => $own]);
    }

    public function audit(Request $request, string $ref): JsonResponse
    {
        $product = $this->product($ref, true);
        $rates = Rate::where('product_id', $product->id)->pluck('ref')->all();
        $departures = Departure::where('product_id', $product->id)->pluck('ref')->all();
        $holds = Hold::whereIn('departure_id', Departure::where('product_id', $product->id)->select('id'))->pluck('ref')->all();
        $entity = $request->input('filter.entity');
        $entities = array_filter(['Producto' => [$product->ref], 'Tarifa' => $rates, 'Salida' => $departures, 'Disponibilidad' => $holds],
            fn ($k) => ! $entity || $entity === $k, ARRAY_FILTER_USE_KEY);

        return $this->ok($this->history($entities, $request->input('filter.action')));
    }

    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'code' => "{$req}|string|max:30",
            'sku' => 'nullable|string|max:40',
            'name' => "{$req}|string|min:3|max:160",
            'internal_name' => 'nullable|string|max:160',
            'product_type_id' => "{$req}|uuid",
            'category_id' => "{$req}|uuid",
            'subcategory_id' => 'nullable|uuid',
            'primary_destination_id' => "{$req}|uuid",
            'short_description' => 'nullable|string|max:2000',
            'long_description' => 'nullable|string|max:20000',
            'duration_value' => "{$req}|numeric|min:1|max:99999",
            'duration_unit' => [$req, Rule::in(Product::DURATION_UNITS)],
            'modality' => [$req, Rule::in(Product::MODALITIES)],
            'minimum_passengers' => "{$req}|integer|min:1|max:999",
            'maximum_passengers' => "{$req}|integer|min:1|max:999",
            'minimum_age' => 'sometimes|integer|min:0|max:120',
            'maximum_age' => 'sometimes|integer|min:0|max:120',
            'booking_advance_min_days' => 'sometimes|integer|min:0|max:3650',
            'booking_advance_max_days' => 'sometimes|integer|min:0|max:3650',
            'is_bookable' => 'sometimes|boolean',
            'is_quoteable' => 'sometimes|boolean',
            'is_b2b' => 'sometimes|boolean',
            'is_ecommerce' => 'sometimes|boolean',
            'is_featured' => 'sometimes|boolean',
        ];
    }

    /** Reglas de tarifa (CAT-BR-13), reutilizadas por el asistente y el alta individual. */
    public static function rateRules(string $prefix = ''): array
    {
        return [
            "{$prefix}rate_type" => ['required', Rule::in(Rate::TYPES)],
            "{$prefix}segment" => ['required', Rule::in(Rate::SEGMENTS)],
            "{$prefix}channel" => ['required', Rule::in(Rate::CHANNELS)],
            "{$prefix}currency" => 'required|in:PEN,USD',
            "{$prefix}price_basis" => ['nullable', Rule::in(Rate::BASES)],
            "{$prefix}cost" => 'required|numeric|min:0',
            "{$prefix}price" => 'required|numeric|gt:0',
            "{$prefix}min_price" => 'nullable|numeric|min:0',
            "{$prefix}max_price" => 'nullable|numeric|min:0',
            "{$prefix}valid_from" => 'required|date_format:Y-m-d',
            "{$prefix}valid_to" => 'required|date_format:Y-m-d',
            "{$prefix}season_id" => 'nullable|uuid',
            "{$prefix}conditions" => 'nullable|string|max:300',
        ];
    }
}
