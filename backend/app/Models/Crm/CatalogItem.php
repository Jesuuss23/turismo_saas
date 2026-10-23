<?php

namespace App\Models\Crm;

class CatalogItem extends CrmModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'crm_catalog_items';

    protected function casts(): array
    {
        return ['requires_detail' => 'boolean', 'is_active' => 'boolean'];
    }
}
