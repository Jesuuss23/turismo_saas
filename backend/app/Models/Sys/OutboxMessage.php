<?php

namespace App\Models\Sys;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OutboxMessage extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'sys_outbox';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'available_at' => 'datetime', 'dispatched_at' => 'datetime'];
    }
}
