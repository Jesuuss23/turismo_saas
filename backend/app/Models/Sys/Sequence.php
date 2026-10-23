<?php

namespace App\Models\Sys;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Sequence extends Model
{
    use BelongsToTenant, HasUuids;

    public const CREATED_AT = null;

    protected $table = 'sys_sequences';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['year_reset' => 'boolean', 'next_number' => 'integer', 'pad_width' => 'integer'];
    }
}
