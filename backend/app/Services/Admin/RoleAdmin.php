<?php

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Models\Iam\Permission;
use App\Models\Iam\Role;
use App\Models\Iam\User;
use App\Security\Access;
use App\Security\AccessMap;
use App\Security\AccessSubject;
use App\Security\PermissionCatalog;
use App\Security\RoleCatalog;
use App\Services\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles y matriz de permisos editable por tenant (spec 09 §4.4, RBAC-BR-01…09). Cada casilla se audita y aplica en la
 * siguiente solicitud del usuario (se vacía la caché de spatie del tenant).
 */
class RoleAdmin
{
    public const SCOPES = ['tenant' => 'Back-office (tenant)', 'mobile' => 'App móvil', 'canal' => 'Canal externo'];

    private const EXPERIENCE = ['tenant' => 'backoffice', 'mobile' => 'mobile', 'canal' => 'portal'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** @return Collection<int, Role> */
    public function roles(): Collection
    {
        return Role::where('tenant_id', $this->context->id())->with('permissions')->orderBy('is_custom')->orderBy('id')->get();
    }

    public function list(): array
    {
        $users = DB::table('model_has_roles as m')->join('iam_users as u', 'u.id', '=', 'm.model_id')->where('u.status', User::ACTIVE)->whereNull('u.deleted_at')
            ->selectRaw('m.role_id, count(*) as n')->groupBy('m.role_id')->pluck('n', 'role_id');

        return $this->roles()->map(fn (Role $r) => $this->row($r, (int) ($users[$r->id] ?? 0)))->all();
    }

    public function row(Role $r, ?int $users = null): array
    {
        return [
            'id' => $r->id, 'name' => $r->name, 'display_name' => $r->display_name, 'description' => $r->description, 'scope' => $r->scope,
            'scope_label' => self::SCOPES[$r->scope] ?? $r->scope, 'is_custom' => (bool) $r->is_custom, 'experience' => $r->experience,
            'permissions' => $r->permissions->count(), 'users' => $users ?? $this->usersOf($r),
        ];
    }

    public function catalog(): array
    {
        return collect(PermissionCatalog::GROUPS)->map(fn ($perms, $module) => ['module' => $module,
            'permissions' => collect($perms)->map(fn ($label, $name) => ['name' => $name, 'label' => $label])->values()->all()])->values()->all();
    }

    public function matrix(): array
    {
        return ['roles' => $this->roles()->map(fn (Role $r) => ['id' => $r->id, 'name' => $r->name, 'display_name' => $r->display_name, 'scope' => $r->scope,
            'is_custom' => (bool) $r->is_custom, 'permissions' => $r->permissions->pluck('name')->values()->all()])->all(), 'modules' => $this->catalog()];
    }

    /** RBAC-BR-06: nombre único, descripción obligatoria; al copiar a un rol no-tenant se descartan los permisos prohibidos. */
    public function create(array $input, User $actor): array
    {
        $data = Validator::make($input, [
            'name' => 'required|string|max:80', 'description' => 'required|string|max:200', 'scope' => 'required|in:'.implode(',', array_keys(self::SCOPES)),
            'copy_from_role' => 'nullable|string',
        ], ['description.required' => 'Describa el propósito del rol.'])->validate();
        if (Role::where('tenant_id', $this->context->id())->whereRaw('lower(display_name) = ?', [mb_strtolower(trim($data['name']))])->exists()) {
            throw ApiException::unprocessable('RBAC-BR-06', 'Ya existe un rol con ese nombre.', ['errors' => ['name' => ['Nombre de rol duplicado.']]]);
        }
        $source = ! empty($data['copy_from_role']) ? $this->find($data['copy_from_role']) : null;

        return DB::transaction(function () use ($data, $source, $actor) {
            $role = Role::create([
                'tenant_id' => $this->context->id(), 'name' => 'custom_'.Str::slug($data['name'], '_').'_'.Str::lower(Str::random(4)), 'guard_name' => PermissionCatalog::GUARD,
                'display_name' => trim($data['name']), 'description' => trim($data['description']), 'scope' => $data['scope'], 'is_custom' => true,
                'experience' => self::EXPERIENCE[$data['scope']],
            ]);
            $copied = $source ? $source->permissions->pluck('name')->filter(fn ($p) => $this->blockReason($role, $p) === null)->values()->all() : [];
            $role->syncPermissions($copied);
            $this->flush();
            $this->audit->log('Rol', $role->display_name, 'crear_rol', null, ($source ? "copia de {$source->display_name} · " : '').count($copied).' permisos', actor: $actor);

            return $this->row($role->load('permissions'), 0);
        });
    }

    /** Solo personalizados: nombre y descripción; el alcance es inmutable (RBAC-BR-06). */
    public function update(Role $role, array $input, User $actor): array
    {
        $this->assertCustom($role, 'editar');
        $data = Validator::make($input, ['name' => 'sometimes|required|string|max:80', 'description' => 'sometimes|required|string|max:200', 'scope' => 'prohibited'],
            ['scope.prohibited' => 'El alcance de un rol no cambia después de crearlo.'])->validate();
        if (isset($data['name']) && Role::where('tenant_id', $this->context->id())->whereKeyNot($role->id)->whereRaw('lower(display_name) = ?', [mb_strtolower(trim($data['name']))])->exists()) {
            throw ApiException::unprocessable('RBAC-BR-06', 'Ya existe un rol con ese nombre.', ['errors' => ['name' => ['Nombre de rol duplicado.']]]);
        }
        $before = "{$role->display_name} · {$role->description}";
        $role->forceFill(['display_name' => trim($data['name'] ?? $role->display_name), 'description' => trim($data['description'] ?? $role->description)])->save();
        $this->audit->log('Rol', $role->display_name, 'editar_rol', $before, "{$role->display_name} · {$role->description}", actor: $actor);

        return $this->row($role->load('permissions'));
    }

    /** RBAC-BR-07: solo personalizados y sin usuarios asignados. */
    public function delete(Role $role, User $actor): void
    {
        $this->assertCustom($role, 'eliminar');
        $assigned = DB::table('model_has_roles')->where('role_id', $role->id)->count();
        if ($assigned > 0) {
            throw ApiException::rule('RBAC-BR-07', "El rol tiene {$assigned} usuario(s) asignado(s): cámbieles el rol antes de eliminarlo.");
        }
        DB::transaction(function () use ($role, $actor) {
            DB::table('role_has_permissions')->where('role_id', $role->id)->delete();
            $role->delete();
            $this->flush();
            $this->audit->log('Rol', $role->display_name, 'eliminar_rol', $role->description, null, actor: $actor);
        });
    }

    public function grant(Role $role, string $permission, User $actor): array
    {
        $perm = $this->permission($permission);
        if ($reason = $this->blockReason($role, $permission)) {
            throw ApiException::unprocessable($reason[0], $reason[1]);
        }
        if (! $role->hasPermissionTo($perm)) {
            DB::transaction(function () use ($role, $perm, $actor) {
                $role->givePermissionTo($perm);
                $this->flush();
                $this->audit->log('Permiso', $role->display_name, 'otorgar_permiso', null, $perm->name, actor: $actor);
            });
        }

        return $this->row($role->load('permissions'));
    }

    public function revoke(Role $role, string $permission, User $actor): array
    {
        $perm = $this->permission($permission);
        if ($role->name === 'tenant_admin' && in_array($permission, ['admin.roles.manage', 'admin.users.manage'], true)) {
            throw ApiException::unprocessable('RBAC-BR-02', 'Anti-bloqueo: el Tenant Admin no puede perder la gestión de usuarios ni de roles.');
        }
        if ($role->hasPermissionTo($perm)) {
            DB::transaction(function () use ($role, $perm, $actor) {
                $role->revokePermissionTo($perm);
                $this->flush();
                $this->audit->log('Permiso', $role->display_name, 'revocar_permiso', $perm->name, null, actor: $actor);
            });
        }

        return $this->row($role->load('permissions'));
    }

    /** RBAC-BR-08: aplica los permitidos del módulo e informa los bloqueados. */
    public function bulk(Role $role, string $module, bool $grant, User $actor): array
    {
        $perms = array_keys(PermissionCatalog::GROUPS[$module] ?? throw ApiException::invalid('module', 'Módulo inexistente.'));
        $applied = [];
        $blocked = [];
        foreach ($perms as $p) {
            $has = $role->permissions->contains('name', $p);
            if ($grant === $has) {
                continue;
            }
            try {
                $grant ? $this->grant($role, $p, $actor) : $this->revoke($role, $p, $actor);
                $applied[] = $p;
            } catch (ApiException $e) {
                $blocked[] = ['permission' => $p, 'code' => $e->errorCode, 'reason' => $e->getMessage()];
            }
            $role->load('permissions');
        }

        return ['applied' => $applied, 'blocked' => $blocked, 'message' => count($applied).' cambio(s) aplicado(s)'.($blocked ? ', '.count($blocked).' bloqueado(s) por reglas de seguridad' : '').'.'];
    }

    /** Mapa Rol × Pantalla con la misma regla del router (00 §7.4) + resumen por rol. */
    public function accessMap(): array
    {
        $screens = collect(AccessMap::SCREENS)->filter(fn ($s) => $s[2] !== null && $s[2] !== 'Plataforma')->map(fn ($s, $route) => ['route' => $route, 'label' => $s[1], 'group' => $s[2]])->values();
        $roles = $this->roles()->map(function (Role $r) use ($screens) {
            $subject = new AccessSubject($r->name, $r->permissions->pluck('name')->all(), $r->scope, $r->display_name, 'CLIENTE');
            $ux = $subject->ux();
            $cells = $screens->mapWithKeys(fn ($s) => [$s['route'] => Access::check($subject, $s['route'])->allowed])->all();
            $can = array_keys(array_filter($cells));

            return [
                'id' => $r->id, 'name' => $r->name, 'display_name' => $r->display_name, 'scope' => $r->scope, 'experience' => $ux['experience'], 'home' => $ux['home'],
                'cells' => $cells, 'can' => count($can), 'cannot' => count($cells) - count($can),
                'reach' => match (true) {
                    RoleCatalog::isExternal($r->name) || $r->scope !== 'tenant' => 'Solo sus registros vinculados ('.$ux['label'].')',
                    $r->name === 'sales' => 'Su cartera (owner = usuario)',
                    default => 'Todo el tenant',
                },
                'can_labels' => $screens->whereIn('route', $can)->pluck('label')->take(8)->values()->all(),
            ];
        });

        return ['screens' => $screens->all(), 'roles' => $roles->all()];
    }

    public function permissionAudit(): array
    {
        return DB::table('audit_events')->whereIn('entity', ['Permiso', 'Rol'])->orderByDesc('occurred_at')->limit(12)
            ->get(['code', 'entity', 'entity_id', 'action', 'before_value', 'after_value', 'actor_role', 'actor_id', 'occurred_at'])
            ->map(fn ($e) => ['code' => 'AUD-'.$e->code, 'entity' => $e->entity, 'role' => $e->entity_id, 'action' => $e->action, 'before' => $e->before_value,
                'after' => $e->after_value, 'actor' => $e->actor_id ? User::find($e->actor_id)?->name : 'Sistema', 'at' => $e->occurred_at])->all();
    }

    public function find(string $key): Role
    {
        return Role::where('tenant_id', $this->context->id())->where(fn ($q) => ctype_digit($key) ? $q->whereKey((int) $key) : $q->where('name', $key))->first()
            ?? throw ApiException::notFound();
    }

    /** @return array{0: string, 1: string}|null */
    private function blockReason(Role $role, string $permission): ?array
    {
        $external = $role->scope !== 'tenant';

        return match (true) {
            $role->name === RoleCatalog::SUPER_ADMIN => ['RBAC-BR-01', 'El rol Super Admin SaaS no se edita desde un tenant.'],
            $permission === 'admin.tenant.manage' => ['RBAC-BR-05', 'admin.tenant.manage es exclusivo de la plataforma: no se asigna a roles del tenant.'],
            $external && str_starts_with($permission, 'admin.') => ['RBAC-BR-03', 'Los roles de canal o app móvil no reciben permisos de administración.'],
            $external && (str_starts_with($permission, 'finance.') || str_contains($permission, 'profitability') || $permission === 'quotes.discount_override') => ['RBAC-BR-04', 'Los roles de canal o app móvil no reciben permisos financieros, de rentabilidad ni de descuentos.'],
            in_array($permission, PermissionCatalog::extranet(), true) && $role->name !== 'provider' => ['RBAC-BR-03', 'Los permisos de la extranet solo los recibe el rol Proveedor.'],
            default => null,
        };
    }

    private function assertCustom(Role $role, string $action): void
    {
        if (! $role->is_custom) {
            throw ApiException::rule('RBAC-BR-06', "Los roles base no se pueden {$action}; su matriz de permisos sí es editable.");
        }
    }

    private function permission(string $name): Permission
    {
        return Permission::where('name', $name)->where('guard_name', PermissionCatalog::GUARD)->first() ?? throw ApiException::notFound();
    }

    private function usersOf(Role $r): int
    {
        return DB::table('model_has_roles as m')->join('iam_users as u', 'u.id', '=', 'm.model_id')->where('m.role_id', $r->id)->where('u.status', User::ACTIVE)->count();
    }

    /** RBAC-BR-09: aplica en la siguiente solicitud (caché de spatie del tenant). */
    private function flush(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
