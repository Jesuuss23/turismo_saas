<?php

namespace App\Models\Ops;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Vehículo (§3.7). */
class Vehicle extends TenantModel
{
    use SoftDeletes;

    public const STATUSES = ['disponible', 'asignado', 'mantenimiento'];

    protected $table = 'ops_vehicles';

    protected function casts(): array
    {
        return ['soat_expires_on' => 'date', 'capacity' => 'integer'];
    }

    public function label(): string
    {
        return "{$this->plate} · {$this->vehicle_type}";
    }
}
