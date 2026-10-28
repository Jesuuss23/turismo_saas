<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;

/** Contenido traducido de la ficha pública (spec 15 §3.11). */
class ProductTranslation extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'catalog_product_translations';

    protected function casts(): array
    {
        return ['inclusions' => 'array', 'exclusions' => 'array', 'faqs' => 'array'];
    }
}
