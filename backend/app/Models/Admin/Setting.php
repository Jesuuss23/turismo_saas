<?php

namespace App\Models\Admin;

use App\Support\Concerns\OptimisticLock;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Configuración del tenant por sección (spec 09 §3.8). PK (tenant_id, section). */
class Setting extends Model
{
    use BelongsToTenant, OptimisticLock;

    public const CREATED_AT = null;

    public $incrementing = false;

    protected $table = 'sys_settings';

    protected $primaryKey = 'section';

    protected $keyType = 'string';

    protected $guarded = ['tenant_id'];

    protected function casts(): array
    {
        return ['values' => 'array'];
    }

    protected function setKeysForSaveQuery($query): Builder
    {
        return $query->where('tenant_id', $this->tenant_id)->where('section', $this->section);
    }
}
