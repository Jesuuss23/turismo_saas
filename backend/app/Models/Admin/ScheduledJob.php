<?php

namespace App\Models\Admin;

use App\Models\Iam\User;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Job del scheduler (spec 09 §3.14). Los de sistema (JOB-01…09) no se eliminan (JOB-BR-03). */
class ScheduledJob extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'sys_scheduled_jobs';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['paused' => 'boolean', 'is_system' => 'boolean', 'last_run_at' => 'datetime', 'next_run_at' => 'datetime'];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(JobRun::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
