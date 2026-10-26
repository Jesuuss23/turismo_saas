<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Temporada (PROC-019). */
class Season extends TenantModel
{
    use SoftDeletes;

    public const TYPES = ['Alta', 'Media', 'Baja', 'Feriado', 'Fecha especial', 'Personalizada'];

    protected $table = 'catalog_seasons';

    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'priority' => 'integer',
            'factor' => 'decimal:2',
        ];
    }
}
