<?php

namespace App\Services\Catalog;

use App\Models\Catalog\Product;
use App\Models\Catalog\Provider;
use App\Models\Iam\User;
use App\Services\Search\SearchProvider;

/** Búsqueda global de productos (nombre, código, SKU, ref) y proveedores (solo con la ruta de proveedores). */
class CatalogSearchProvider implements SearchProvider
{
    public function __construct(private readonly CatalogAccess $access) {}

    public function search(User $user, string $term, int $limit): array
    {
        if (! $this->access->can($user, CatalogAccess::VIEW)) {
            return [];
        }
        $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';
        $hits = [];
        foreach (Product::where(fn ($q) => $q->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(code) like ?', [$like])
            ->orWhereRaw('lower(sku) like ?', [$like])->orWhereRaw('lower(ref) like ?', [$like]))->orderBy('ref')->limit($limit)->get(['id', 'ref', 'name', 'status']) as $p) {
            $hits[] = ['type' => 'product', 'id' => $p->id, 'code' => $p->ref, 'title' => $p->name, 'subtitle' => Product::STATUS_LABELS[$p->status], 'route' => "catalogo/productos/{$p->ref}"];
        }
        if ($this->access->seeProviders($user)) {
            foreach (Provider::where(fn ($q) => $q->whereRaw('lower(name) like ?', [$like])->orWhere('ruc', 'like', $like)->orWhereRaw('lower(ref) like ?', [$like]))
                ->orderBy('ref')->limit($limit)->get(['id', 'ref', 'name', 'type']) as $p) {
                $hits[] = ['type' => 'provider', 'id' => $p->id, 'code' => $p->ref, 'title' => $p->name, 'subtitle' => $p->type, 'route' => "catalogo/proveedores/{$p->ref}"];
            }
        }

        return $hits;
    }
}
