<?php

namespace App\Services\Crm;

use App\Exceptions\ApiException;
use App\Models\Iam\User;
use Illuminate\Support\Collection;

/** Usuarios elegibles como responsables (CRM-BR-09, CRM-BR-31, §7.3 selector "Responsable"). */
class CrmUsers
{
    public const ADVISOR_ROLES = ['tenant_admin', 'manager', 'sales'];

    public const ACTIVITY_EXTRA_ROLES = ['operations'];

    /** @return Collection<int, User> */
    public function advisors(bool $includeOperations = false): Collection
    {
        $roles = $includeOperations ? [...self::ADVISOR_ROLES, ...self::ACTIVITY_EXTRA_ROLES] : self::ADVISOR_ROLES;

        return User::role($roles)->where('status', User::ACTIVE)->with('roles')->orderBy('name')->get();
    }

    /** @return Collection<int, User> */
    public function managers(): Collection
    {
        return User::role('manager')->where('status', User::ACTIVE)->get();
    }

    public function assertAssignable(?string $userId, string $field = 'owner_id', bool $includeOperations = false): User
    {
        $user = $userId ? $this->advisors($includeOperations)->firstWhere('id', $userId) : null;
        if ($user === null) {
            throw ApiException::invalid($field, 'El responsable debe ser un asesor activo'.($includeOperations ? ' o un usuario de Operaciones activo' : ''));
        }

        return $user;
    }
}
