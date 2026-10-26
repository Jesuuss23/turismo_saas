<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Models\Catalog\Category;
use App\Models\Catalog\Destination;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductChannel;
use App\Models\Catalog\ProductDestination;
use App\Models\Catalog\ProductType;
use App\Models\Catalog\Provider;
use App\Models\Catalog\Rate;
use App\Models\Catalog\Season;
use App\Models\Catalog\Subcategory;
use App\Models\Catalog\SupplierService;
use App\Services\Catalog\DestinationService;
use App\Services\Catalog\Links\CatalogLinks;
use App\Services\Catalog\ProductParts;
use App\Services\Catalog\ProductQuery;
use App\Services\Catalog\TaxonomyService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Spec 02 §6.5: tipos, categorías y destinos, más los catálogos de los formularios. */
class ReferenceController extends CatalogController
{
    /** Opciones de formularios del catálogo (asistente, 360, salidas). */
    public function meta(Request $request, CatalogLinks $links, TenantContext $tenant): JsonResponse
    {
        $user = $this->actor($request);

        return $this->ok([
            'types' => ProductType::orderBy('sort_order')->get(['id', 'name', 'requires_itinerary', 'requires_components']),
            'categories' => Category::with(['subcategories' => fn ($q) => $q->orderBy('name')])->orderBy('ref')->get()
                ->map(fn ($c) => ['id' => $c->id, 'ref' => $c->ref, 'name' => $c->name, 'subcategories' => $c->subcategories->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->all()]),
            'destinations' => Destination::orderBy('path')->get()->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'type' => $d->type, 'level' => $d->level, 'parent_id' => $d->parent_id]),
            'providers' => Provider::orderBy('name')->get()->map(fn ($p) => ['id' => $p->id, 'ref' => $p->ref, 'name' => $p->name, 'type' => $p->type, 'status' => $p->effectiveStatus(), 'usable' => $p->isUsable()]),
            'services' => SupplierService::with('provider')->orderBy('name')->get()->map(fn ($s) => $this->present->service($s, $user)),
            'seasons' => Season::where('status', 'activa')->orderBy('date_from')->get()->map(fn ($s) => $this->present->season($s)),
            'products' => Product::orderBy('ref')->get(['id', 'ref', 'name', 'status'])->map(fn ($p) => ['id' => $p->id, 'ref' => $p->ref, 'name' => $p->name, 'status' => $p->status]),
            'agencies' => $this->access->seeNetRates($user) ? $links->agencies() : [],
            'guides' => $links->guides(),
            'vehicles' => $links->vehicles(),
            'enums' => [
                'durations' => Product::DURATION_UNITS, 'modalities' => Product::MODALITIES, 'availability_models' => Product::AVAILABILITY_MODELS,
                'rate_types' => Rate::TYPES, 'segments' => Rate::SEGMENTS, 'rate_channels' => Rate::CHANNELS, 'price_bases' => Rate::BASES,
                'units' => SupplierService::UNITS, 'service_types' => SupplierService::TYPES, 'provider_types' => Provider::TYPES,
                'itinerary_types' => ProductParts::ITEM_TYPES, 'requirement_categories' => ProductParts::REQUIREMENT_CATEGORIES,
                'relations' => ProductParts::RELATIONS, 'channels' => ProductChannel::CHANNELS, 'destination_types' => array_keys(Destination::LEVELS),
            ],
            'fx_usd' => $tenant->tenant()?->fxToPen('USD'),
            'sku_prefix' => strtoupper(substr((string) $tenant->tenant()?->slug, 0, 3)),
            'can' => $this->access->actions($user),
        ]);
    }

    // ── Tipos y categorías (CAT-BR-36) ──────────────────────────────────

    public function taxonomy(): JsonResponse
    {
        $byType = Product::groupBy('product_type_id')->selectRaw('product_type_id, count(*) as n')->pluck('n', 'product_type_id');
        $byCategory = Product::groupBy('category_id')->selectRaw('category_id, count(*) as n')->pluck('n', 'category_id');
        $bySub = Product::whereNotNull('subcategory_id')->groupBy('subcategory_id')->selectRaw('subcategory_id, count(*) as n')->pluck('n', 'subcategory_id');

        return $this->ok([
            'types' => ProductType::orderBy('sort_order')->get()->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'requires_itinerary' => $t->requires_itinerary,
                'requires_components' => $t->requires_components, 'products' => (int) ($byType[$t->id] ?? 0)]),
            'categories' => Category::with('subcategories')->orderBy('ref')->get()->map(fn ($c) => ['id' => $c->id, 'ref' => $c->ref, 'name' => $c->name, 'products' => (int) ($byCategory[$c->id] ?? 0),
                'subcategories' => $c->subcategories->sortBy('name')->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'products' => (int) ($bySub[$s->id] ?? 0)])->values()->all()]),
        ]);
    }

    public function storeType(Request $request, TaxonomyService $taxonomy): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:60', 'requires_itinerary' => 'nullable|boolean', 'requires_components' => 'nullable|boolean']);
        $type = $taxonomy->createType($data, $this->actor($request));

        return $this->created(['id' => $type->id, 'name' => $type->name]);
    }

    public function destroyType(Request $request, string $id, TaxonomyService $taxonomy): JsonResponse
    {
        $taxonomy->deleteType($this->find(ProductType::query(), $id, 'id'), $this->actor($request));

        return $this->ok(['deleted' => true]);
    }

    public function storeCategory(Request $request, TaxonomyService $taxonomy): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:80', 'subcategories' => 'nullable|array', 'subcategories.*' => 'string|max:80']);
        $category = $taxonomy->createCategory($data, $this->actor($request));

        return $this->created(['id' => $category->id, 'ref' => $category->ref]);
    }

    public function destroyCategory(Request $request, string $id, TaxonomyService $taxonomy): JsonResponse
    {
        $taxonomy->deleteCategory($this->find(Category::query(), $id), $this->actor($request));

        return $this->ok(['deleted' => true]);
    }

    public function storeSubcategory(Request $request, string $id, TaxonomyService $taxonomy): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:80']);
        $sub = $taxonomy->createSubcategory($this->find(Category::query(), $id), $data['name'], $this->actor($request));

        return $this->created(['id' => $sub->id]);
    }

    public function destroySubcategory(Request $request, string $id, string $sub, TaxonomyService $taxonomy): JsonResponse
    {
        $category = $this->find(Category::query(), $id);
        $taxonomy->deleteSubcategory($this->child(Subcategory::class, $sub, 'category_id', $category->id), $this->actor($request));

        return $this->ok(['deleted' => true]);
    }

    // ── Destinos (PROC-011, CAT-BR-39) ──────────────────────────────────

    public function destinations(): JsonResponse
    {
        $all = Destination::orderBy('path')->get();
        $direct = Product::groupBy('primary_destination_id')->selectRaw('primary_destination_id as d, count(*) as n')->pluck('n', 'd');
        $secondary = ProductDestination::groupBy('destination_id')->selectRaw('destination_id as d, count(*) as n')->pluck('n', 'd');

        return $this->ok($all->map(fn (Destination $d) => $this->present->destination($d, [
            'products' => (int) ($direct[$d->id] ?? 0) + (int) ($secondary[$d->id] ?? 0),
            // Contador con subdestinos: suma de nodos cuyo path empieza por el del destino.
            'products_tree' => $all->filter(fn ($x) => str_starts_with($x->path, $d->path))->sum(fn ($x) => (int) ($direct[$x->id] ?? 0) + (int) ($secondary[$x->id] ?? 0)),
            'children' => $all->where('parent_id', $d->id)->count(),
        ]))->all());
    }

    public function showDestination(Request $request, string $id, DestinationService $destinations, ProductQuery $query): JsonResponse
    {
        $destination = $this->find(Destination::query(), $id);
        $user = $this->actor($request);
        $products = $query->query(['destination' => $destination->id])->get();
        $prices = $query->fromPrices($products, $user);

        return $this->ok($this->present->destination($destination, [
            'path' => $destinations->pathLabel($destination),
            'ancestors' => array_map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'type' => $a->type], $destinations->ancestors($destination)),
            'children' => Destination::where('parent_id', $destination->id)->orderBy('name')->get()->map(fn ($c) => $this->present->destination($c))->all(),
            'products' => $products->map(fn ($p) => $this->present->productRow($p, $user, $prices[$p->id] ?? null))->all(),
        ]));
    }

    public function storeDestination(Request $request, DestinationService $destinations): JsonResponse
    {
        $result = $destinations->create($this->destinationRules($request), $this->actor($request));

        return $this->created($this->present->destination($result['destination']), $result['warnings']);
    }

    public function updateDestination(Request $request, string $id, DestinationService $destinations): JsonResponse
    {
        $result = $destinations->update($this->find(Destination::query(), $id), $this->destinationRules($request, true), $this->actor($request));

        return $this->ok($this->present->destination($result['destination']), $result['warnings'] ? ['warnings' => $result['warnings']] : []);
    }

    public function destroyDestination(Request $request, string $id, DestinationService $destinations): JsonResponse
    {
        $destinations->delete($this->find(Destination::query(), $id), $this->actor($request));

        return $this->ok(['deleted' => true]);
    }

    private function destinationRules(Request $request, bool $editing = false): array
    {
        $req = $editing ? 'sometimes' : 'required';

        return $request->validate([
            'name' => "{$req}|string|max:120",
            'type' => [$req, Rule::in(array_keys(Destination::LEVELS))],
            'parent_id' => 'sometimes|nullable|uuid',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'description' => 'nullable|string|max:5000',
        ]);
    }
}
