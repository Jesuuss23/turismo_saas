<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Rango de disponibilidad por fechas (spec 16 §3.3, CAT-AV-01..05). */
class AvailabilityRange extends TenantModel
{
    use OptimisticLock, SoftDeletes;

    public const STATUSES = ['disponible', 'cerrado', 'agotado'];

    protected $table = 'catalog_availability_ranges';

    protected function casts(): array
    {
        return ['date_from' => 'date', 'date_to' => 'date', 'capacity_per_day' => 'integer'];
    }

    public function covers(string $date): bool
    {
        return $this->date_from->toDateString() <= $date && $date <= $this->date_to->toDateString();
    }
}
