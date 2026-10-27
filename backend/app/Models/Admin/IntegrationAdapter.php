<?php

namespace App\Models\Admin;

use App\Models\Iam\User;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Adapter de integración (spec 09 §3.13). El secreto vive en la bóveda; aquí solo su referencia (INT-BR-03). */
class IntegrationAdapter extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'integration_adapters';

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['secret_ref'];

    protected function casts(): array
    {
        return ['last_test_at' => 'datetime', 'last_test_ok' => 'boolean', 'secret_rotated_at' => 'datetime'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
