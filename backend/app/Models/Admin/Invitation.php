<?php

namespace App\Models\Admin;

use App\Models\Iam\User;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Invitación de un solo uso, 72 h (USR-BR-11). Solo se guarda el hash del token. */
class Invitation extends Model
{
    use BelongsToTenant, HasUuids;

    public const TTL_HOURS = 72;

    public const UPDATED_AT = null;

    protected $table = 'iam_user_invitations';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime', 'require_mfa' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
