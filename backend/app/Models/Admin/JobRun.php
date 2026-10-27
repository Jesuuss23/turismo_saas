<?php

namespace App\Models\Admin;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class JobRun extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $table = 'sys_job_runs';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
