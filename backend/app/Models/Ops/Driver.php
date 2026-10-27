<?php

namespace App\Models\Ops;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Conductor (§3.7). */
class Driver extends TenantModel
{
    use SoftDeletes;

    public const STATUSES = ['disponible', 'asignado', 'descanso', 'vacaciones'];

    protected $table = 'ops_drivers';

    protected function casts(): array
    {
        return ['license_expires_on' => 'date'];
    }

    public function label(): string
    {
        return $this->full_name;
    }
}
