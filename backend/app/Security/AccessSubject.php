<?php

namespace App\Security;

/** Lo que Access::check necesita saber del usuario, sin depender de Eloquent. */
final class AccessSubject
{
    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        public readonly string $role,
        public readonly array $permissions,
        public readonly ?string $roleScope = null,
        public readonly ?string $roleLabel = null,
        public readonly ?string $clientId = null,
    ) {}

    public function ux(): array
    {
        return RoleUx::for($this->role, $this->roleScope, $this->clientId);
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /** @param list<string> $permissions */
    public function canAny(array $permissions): bool
    {
        return array_intersect($permissions, $this->permissions) !== [];
    }
}
