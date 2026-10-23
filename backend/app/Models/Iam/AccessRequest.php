<?php

namespace App\Models\Iam;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessRequest extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'iam_access_requests';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    /** Columna text[] de PostgreSQL. */
    protected function requiredPermissions(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null || $value === '{}' ? [] : str_getcsv(trim($value, '{}')),
            set: fn (array $value) => '{'.implode(',', array_map(fn ($p) => '"'.addcslashes($p, '"\\').'"', $value)).'}',
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
