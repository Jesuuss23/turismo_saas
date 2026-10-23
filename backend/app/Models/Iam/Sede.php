<?php

namespace App\Models\Iam;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sede extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'iam_sedes';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['is_main' => 'boolean'];
    }
}
