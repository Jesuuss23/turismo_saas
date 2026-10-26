<?php

namespace App\Services\Catalog;

use App\Exceptions\ApiException;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductType;
use App\Models\Catalog\Subcategory;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\SequenceService;
use Illuminate\Support\Facades\DB;

/** Tipos de producto y categorías/subcategorías configurables (Spec PRD §3–§4, CAT-BR-36). */
class TaxonomyService
{
    public function __construct(
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
    ) {}

    public function createType(array $data, User $actor): ProductType
    {
        $name = mb_strtoupper(trim($data['name']));
        if (ProductType::whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
            throw ApiException::invalid('name', 'El tipo ya existe.');
        }

        return DB::transaction(function () use ($name, $data, $actor) {
            $type = ProductType::create([
                'name' => $name,
                'requires_itinerary' => (bool) ($data['requires_itinerary'] ?? false),
                'requires_components' => (bool) ($data['requires_components'] ?? true),
                'sort_order' => (int) ProductType::max('sort_order') + 1,
            ]);
            $this->audit->log('Catálogo', $name, 'crear_tipo', null, $name, actor: $actor);

            return $type;
        });
    }

    public function deleteType(ProductType $type, User $actor): void
    {
        $this->ensureUnused(Product::where('product_type_id', $type->id)->count(), "el tipo {$type->name}");
        DB::transaction(function () use ($type, $actor) {
            $type->delete();
            $this->audit->log('Catálogo', $type->name, 'eliminar_tipo', $type->name, null, actor: $actor);
        });
    }

    public function createCategory(array $data, User $actor): Category
    {
        $name = trim($data['name']);
        if (Category::whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
            throw ApiException::invalid('name', 'La categoría ya existe.');
        }

        return DB::transaction(function () use ($name, $data, $actor) {
            $category = Category::create(['ref' => $this->sequences->next('catalog_category'), 'name' => $name]);
            foreach (array_unique(array_filter(array_map('trim', $data['subcategories'] ?? []))) as $sub) {
                Subcategory::create(['category_id' => $category->id, 'name' => $sub]);
            }
            $this->audit->log('Catálogo', $category->ref, 'crear_categoria', null, $name, actor: $actor);

            return $category;
        });
    }

    public function deleteCategory(Category $category, User $actor): void
    {
        $this->ensureUnused(Product::where('category_id', $category->id)->count(), "la categoría {$category->name}");
        DB::transaction(function () use ($category, $actor) {
            Subcategory::where('category_id', $category->id)->delete();
            $category->delete();
            $this->audit->log('Catálogo', $category->ref, 'eliminar_categoria', $category->name, null, actor: $actor);
        });
    }

    public function createSubcategory(Category $category, string $name, User $actor): Subcategory
    {
        $name = trim($name);
        if (Subcategory::where('category_id', $category->id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
            throw ApiException::invalid('name', 'La subcategoría ya existe en la categoría.');
        }

        return DB::transaction(function () use ($category, $name, $actor) {
            $sub = Subcategory::create(['category_id' => $category->id, 'name' => $name]);
            $this->audit->log('Catálogo', $category->ref, 'crear_subcategoria', null, "{$category->name} › {$name}", actor: $actor);

            return $sub;
        });
    }

    public function deleteSubcategory(Subcategory $sub, User $actor): void
    {
        $this->ensureUnused(Product::where('subcategory_id', $sub->id)->count(), "la subcategoría {$sub->name}");
        DB::transaction(function () use ($sub, $actor) {
            $sub->delete();
            $this->audit->log('Catálogo', $sub->category?->ref ?? $sub->id, 'eliminar_subcategoria', $sub->name, null, actor: $actor);
        });
    }

    private function ensureUnused(int $count, string $what): void
    {
        if ($count > 0) {
            throw ApiException::rule('CAT-BR-36', "No se puede eliminar: {$count} producto(s) usan {$what}.", ['products' => $count]);
        }
    }
}
