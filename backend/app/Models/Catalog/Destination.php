<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Spec 02 §3.3 (PROC-011). `path` = "/id-raíz/…/id-propio/". */
class Destination extends TenantModel
{
    use SoftDeletes;

    public const LEVELS = ['País' => 0, 'Región' => 1, 'Departamento' => 2, 'Provincia' => 3, 'Ciudad' => 4, 'Zona' => 4, 'Lugar' => 5, 'Atracción' => 6, 'Ruta' => 6];

    protected $table = 'catalog_destinations';

    protected function casts(): array
    {
        return [
            'lat' => 'decimal:6',
            'lng' => 'decimal:6',
            'level' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Destination::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Destination::class, 'parent_id');
    }
}
