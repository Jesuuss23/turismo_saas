<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Adicional del producto: museos, entradas, comidas… (spec 16 §3.5). */
class ProductAddon extends TenantModel
{
    use OptimisticLock, SoftDeletes;

    public const CATEGORIES = ['Museo', 'Entrada', 'Alimentación', 'Transporte', 'Extensión', 'Seguro', 'Experiencia', 'Servicio', 'Otro'];

    protected $table = 'catalog_product_addons';

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'applies_to' => 'array', 'is_optional' => 'boolean'];
    }
}
