<?php

namespace App\Models\Iam;

use App\Models\Sanctum\PersonalAccessToken;
use App\Security\AccessSubject;
use App\Security\RoleCatalog;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Usuario de tenant (§3.2). Un rol por usuario; sin permisos directos (todo por rol).
 *
 * @property PersonalAccessToken|null $currentAccessToken
 */
class User extends Authenticatable
{
    use BelongsToTenant, HasApiTokens, HasRoles, HasUuids, Notifiable, SoftDeletes;

    public const PENDING = 'pendiente';

    public const ACTIVE = 'activo';

    public const INACTIVE = 'inactivo';

    /** Vínculo obligatorio por rol externo (SEC-BR-12). */
    public const EXTERNAL_LINKS = [
        'customer' => 'client_id',
        'passenger' => 'reservation_id',
        'b2b' => 'agency_id',
        'provider' => 'provider_id',
        'guide' => 'guide_id',
        'driver' => 'driver_id',
    ];

    protected $table = 'iam_users';

    protected string $guard_name = 'api';

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'mfa_enabled' => 'boolean',
            'mfa_required' => 'boolean',
            'last_login_at' => 'datetime',
            'invited_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function mfaFactor(): HasOne
    {
        return $this->hasOne(MfaFactor::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function primaryRole(): ?Role
    {
        return $this->roles->first();
    }

    public function roleName(): ?string
    {
        return $this->primaryRole()?->name;
    }

    public function isExternal(): bool
    {
        return RoleCatalog::isExternal((string) $this->roleName()) || in_array($this->primaryRole()?->scope, ['mobile', 'canal'], true);
    }

    /** Alcance de datos para /me (own | tenant | linked). */
    public function dataScope(): string
    {
        return match (true) {
            $this->isExternal() => 'linked',
            $this->roleName() === 'sales' => 'own',
            default => 'tenant',
        };
    }

    public function experience(): string
    {
        return $this->primaryRole()?->ux($this->client_id)['experience'] ?? 'backoffice';
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        return $this->getAllPermissions()->pluck('name')->values()->all();
    }

    public function accessSubject(): AccessSubject
    {
        $role = $this->primaryRole();

        return new AccessSubject(
            role: $role?->name ?? '',
            permissions: $this->permissionNames(),
            roleScope: $role?->scope,
            roleLabel: $role?->display_name,
            clientId: $this->client_id,
        );
    }

    /** ¿Exige MFA? Usuarios marcados o roles internos si la política del tenant lo pide (SEC-BR-08). */
    public function requiresMfa(): bool
    {
        if ($this->mfa_enabled || $this->mfa_required) {
            return true;
        }

        return ! $this->isExternal() && (bool) $this->tenant?->requiresMfaForInternalRoles();
    }
}
