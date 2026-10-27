<?php

namespace App\Models\Ops;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Guía (§3.7). */
class Guide extends TenantModel
{
    use SoftDeletes;

    public const STATUSES = ['disponible', 'asignado', 'descanso', 'vacaciones'];

    protected $table = 'ops_guides';

    protected function casts(): array
    {
        return ['languages' => 'array', 'rating' => 'decimal:1', 'license_expires_on' => 'date'];
    }

    public function label(): string
    {
        return $this->full_name;
    }
}
