<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Grupo de personalización (§23). */
class ConfigGroup extends TenantModel
{
    protected $table = 'catalog_product_config_groups';

    public function options(): HasMany
    {
        return $this->hasMany(ConfigOption::class, 'group_id');
    }
}
