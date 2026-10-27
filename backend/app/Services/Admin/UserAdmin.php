<?php

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Models\Admin\Invitation;
use App\Models\Iam\MfaFactor;
use App\Models\Iam\PlatformUser;
use App\Models\Iam\Role;
use App\Models\Iam\Sede;
use App\Models\Iam\User;
use App\Models\Tenant;
use App\Security\PermissionCatalog;
use App\Security\RoleCatalog;
use App\Services\AuditLogger;
use App\Services\Auth\TokenRevoker;
use App\Services\Notify;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Identidad del tenant (PROC-002, spec 09 §4.2): invitación → pendiente → activo ⇄ inactivo, con anti-auto-escalamiento
 * (USR-BR-04/06), al menos un Tenant Admin activo (USR-BR-05) y límite de usuarios del plan con bloqueo (USR-BR-02).
 */
class UserAdmin
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
        private readonly TokenRevoker $tokens,
    ) {}

    public function list(array $filters): array
    {
        $users = User::with('roles', 'sede')->orderBy('code')->get();
        $rows = $users->filter(fn (User $u) => (empty($filters['role']) || $u->roleName() === $filters['role'])
            && (empty($filters['sede']) || $u->sede_id === $filters['sede'])
            && (empty($filters['status']) || $u->status === $filters['status'])
            && (empty($filters['mfa']) || ($filters['mfa'] === 'si') === (bool) $u->mfa_enabled)
            && (empty($filters['q']) || Str::contains(Str::lower($u->name.' '.$u->email.' '.$u->code), Str::lower($filters['q']))));
        $internal = fn (User $u) => ! $u->isExternal();
        $active = $users->where('status', User::ACTIVE);
        $tenant = $this->tenantRow();

        return [
            'data' => $rows->map(fn (User $u) => $this->row($u))->values()->all(),
            'meta' => [
                'kpis' => [
                    'internal_active' => $active->filter($internal)->count(),
                    'channel_active' => $active->reject($internal)->count(),
                    'without_mfa' => $active->filter($internal)->where('mfa_enabled', false)->count(),
                    'pending' => $users->where('status', User::PENDING)->count(),
                    'sedes' => Sede::where('status', 'activa')->count(),
                ],
                'plan' => ['name' => $tenant->plan?->name, 'max_users' => $tenant->plan?->max_users, 'used' => $this->usedSeats(), 'max_sedes' => $tenant->plan?->max_sedes],
                'roles' => $this->roleOptions(),
                'sedes' => Sede::orderBy('code')->get(['id', 'code', 'name', 'status'])->all(),
            ],
        ];
    }

    public function row(User $u): array
    {
        $role = $u->primaryRole();

        return [
            'id' => $u->id, 'code' => $u->code, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone,
            'role' => $role?->name, 'role_label' => $role?->display_name, 'scope' => $role?->scope, 'external' => $u->isExternal(),
            'sede_id' => $u->sede_id, 'sede' => $u->sede?->name, 'status' => $u->status,
            'status_label' => ['pendiente' => 'Invitación enviada', 'activo' => 'Activo', 'inactivo' => 'Inactivo'][$u->status] ?? $u->status,
            'mfa' => (bool) $u->mfa_enabled, 'mfa_required' => (bool) $u->mfa_required,
            'last_login_at' => $u->last_login_at?->toIso8601String(), 'invited_at' => $u->invited_at?->toIso8601String(),
            'deactivated_at' => $u->deactivated_at?->toIso8601String(), 'deactivated_reason' => $u->deactivated_reason, 'lock_version' => (int) $u->lock_version,
        ];
    }

    public function detail(User $u, User $actor): array
    {
        $u->load('roles', 'sede');
        $permissions = $u->primaryRole()?->permissions->pluck('name')->all() ?? [];
        $groups = [];
        foreach (PermissionCatalog::GROUPS as $module => $perms) {
            $granted = array_values(array_intersect(array_keys($perms), $permissions));
            if ($granted !== []) {
                $groups[] = ['module' => $module, 'permissions' => array_map(fn ($p) => ['name' => $p, 'label' => $perms[$p]], $granted)];
            }
        }
        $activity = DB::table('audit_events')->where(fn ($q) => $q->where('actor_id', $u->id)->orWhere(fn ($q) => $q->where('entity', 'Usuario')->where('entity_id', $u->code)))
            ->orderByDesc('occurred_at')->limit(8)->get(['code', 'entity', 'entity_id', 'action', 'after_value', 'occurred_at'])
            ->map(fn ($e) => ['code' => 'AUD-'.$e->code, 'entity' => $e->entity, 'entity_id' => $e->entity_id, 'action' => $e->action, 'detail' => $e->after_value, 'at' => $e->occurred_at])->all();
        $invitation = Invitation::where('user_id', $u->id)->latest('created_at')->first();

        return $this->row($u) + [
            'permissions' => $groups, 'permission_count' => count($permissions), 'activity' => $activity,
            'invitation' => $invitation ? ['expires_at' => $invitation->expires_at->toIso8601String(), 'accepted_at' => $invitation->accepted_at?->toIso8601String(),
                'expired' => $invitation->accepted_at === null && $invitation->expires_at->isPast(), 'resent_count' => $invitation->resent_count, 'require_mfa' => $invitation->require_mfa] : null,
            'is_self' => $u->id === $actor->id,
        ];
    }

    /** @return list<array{value: string, label: string, scope: string}> USR-BR-09: nunca super_admin (no es rol de tenant). */
    public function roleOptions(): array
    {
        return Role::where('tenant_id', $this->context->id())->where('name', '<>', RoleCatalog::SUPER_ADMIN)->orderBy('is_custom')->orderBy('id')->get()
            ->map(fn (Role $r) => ['value' => $r->name, 'label' => $r->display_name, 'scope' => $r->scope])->all();
    }

    public function invite(array $input, User $actor): array
    {
        $data = Validator::make($input, [
            'name' => 'required|string|max:150', 'email' => 'required|email|max:190', 'role' => 'required|string|not_in:'.RoleCatalog::SUPER_ADMIN,
            'sede_id' => 'required|uuid', 'phone' => 'nullable|string|max:30', 'require_mfa' => 'nullable|boolean',
        ], ['role.not_in' => 'El rol Super Admin SaaS no se asigna dentro de un tenant.'])->validate();
        $email = mb_strtolower(trim($data['email']));
        if (User::withTrashed()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ApiException::unprocessable('USR-BR-01', 'Ya existe una identidad con ese correo en el tenant. Reactive el usuario existente en lugar de invitarlo de nuevo.', ['errors' => ['email' => ['Correo ya registrado en el tenant.']]]);
        }
        $role = $this->role($data['role']);
        $sede = Sede::where('status', 'activa')->find($data['sede_id']) ?? throw ApiException::invalid('sede_id', 'Elija una sede activa.');

        return DB::transaction(function () use ($data, $email, $role, $sede, $actor) {
            $this->lockSeats();
            $user = User::create([
                'code' => $this->nextCode(), 'name' => trim($data['name']), 'email' => $email, 'phone' => $data['phone'] ?? null, 'password' => null,
                'sede_id' => $sede->id, 'status' => User::PENDING, 'mfa_required' => (bool) ($data['require_mfa'] ?? true),
                'invited_at' => now(), 'invited_by' => $actor->id, 'created_by' => $actor->id,
            ]);
            $user->assignRole($role->name);
            $link = $this->issueInvitation($user, (bool) ($data['require_mfa'] ?? true), $actor);
            $this->audit->log('Usuario', $user->code, 'crear', null, "invitado · {$role->display_name} · {$sede->name}", actor: $actor);

            return ['user' => $this->row($user->fresh(['roles', 'sede'])), 'message' => "Invitación enviada a {$email}. El enlace vence en 72 horas.", 'invitation_url' => app()->isProduction() ? null : $link];
        });
    }

    /** PROC-001: administrador inicial del tenant recién creado (invitación desde la consola de plataforma). */
    public function inviteInitialAdmin(string $name, string $email, ?string $phone, PlatformUser $actor): string
    {
        $sede = Sede::where('is_main', true)->firstOrFail();
        $user = User::create([
            'code' => $this->nextCode(), 'name' => trim($name), 'email' => mb_strtolower(trim($email)), 'phone' => $phone, 'password' => null,
            'sede_id' => $sede->id, 'status' => User::PENDING, 'mfa_required' => true, 'invited_at' => now(),
        ]);
        $user->assignRole('tenant_admin');
        $link = $this->issueInvitation($user, true, $actor);
        $this->audit->log('Usuario', $user->code, 'crear', null, "administrador inicial invitado · {$sede->name}", actor: $actor);

        return $link;
    }

    public function update(User $u, array $input, ?string $ifMatch, User $actor): array
    {
        if (array_key_exists('email', $input)) {
            throw ApiException::unprocessable('USR-BR-03', 'El correo no se edita después de la invitación.', ['errors' => ['email' => ['El correo no se edita.']]]);
        }
        $data = Validator::make($input, ['name' => 'sometimes|required|string|max:150', 'role' => 'sometimes|required|string|not_in:'.RoleCatalog::SUPER_ADMIN,
            'sede_id' => 'sometimes|required|uuid', 'phone' => 'sometimes|nullable|string|max:30'])->validate();
        $this->assertVersion($u, $ifMatch);
        $u->load('roles', 'sede');
        $oldRole = $u->primaryRole();

        return DB::transaction(function () use ($u, $data, $actor, $oldRole) {
            $edits = [];
            if (isset($data['role']) && $data['role'] !== $oldRole?->name) {
                if ($u->id === $actor->id) {
                    throw ApiException::forbidden('USR-BR-04', 'No puede cambiar su propio rol: pídaselo a otro Tenant Admin.');
                }
                $new = $this->role($data['role']);
                if ($oldRole?->name === 'tenant_admin' && $u->status === User::ACTIVE) {
                    $this->assertAnotherAdmin($u);
                }
                $u->syncRoles([$new->name]);
                app(PermissionRegistrar::class)->forgetCachedPermissions();
                $this->tokens->forUser($u);
                $this->audit->log('Usuario', $u->code, 'cambio_rol', $oldRole?->display_name, $new->display_name, actor: $actor);
            }
            if (isset($data['sede_id']) && $data['sede_id'] !== $u->sede_id) {
                $sede = Sede::where('status', 'activa')->find($data['sede_id']) ?? throw ApiException::invalid('sede_id', 'Elija una sede activa.');
                $edits[] = "sede: {$u->sede?->name} → {$sede->name}";
                $u->sede_id = $sede->id;
            }
            foreach (['name' => 'nombre', 'phone' => 'teléfono'] as $field => $label) {
                if (array_key_exists($field, $data) && $data[$field] !== $u->{$field}) {
                    $edits[] = "{$label}: {$u->{$field}} → {$data[$field]}";
                    $u->{$field} = $data[$field];
                }
            }
            $u->updated_by = $actor->id;
            $u->lock_version = (int) $u->lock_version + 1;
            $u->save();
            if ($edits !== []) {
                $this->audit->log('Usuario', $u->code, 'editar', null, implode(' · ', $edits), actor: $actor);
            }

            return $this->row($u->fresh(['roles', 'sede']));
        });
    }

    /** USR-BR-05/06/07/08: baja lógica con motivo; avisa (no bloquea) de registros abiertos. */
    public function deactivate(User $u, ?string $reason, User $actor): array
    {
        if ($u->id === $actor->id) {
            throw ApiException::forbidden('USR-BR-06', 'No puede desactivar su propia cuenta.');
        }
        if (trim((string) $reason) === '') {
            throw ApiException::invalid('reason', 'Indique el motivo de la baja.');
        }
        if ($u->status === User::INACTIVE) {
            throw ApiException::rule('USR-STATE', 'El usuario ya está inactivo.');
        }
        $u->load('roles');
        if ($u->roleName() === 'tenant_admin' && $u->status === User::ACTIVE) {
            $this->assertAnotherAdmin($u);
        }
        $warnings = $this->openRecords($u);
        DB::transaction(function () use ($u, $reason, $actor) {
            $u->forceFill(['status' => User::INACTIVE, 'deactivated_at' => now(), 'deactivated_reason' => trim((string) $reason), 'updated_by' => $actor->id,
                'lock_version' => (int) $u->lock_version + 1])->save();
            Invitation::where('user_id', $u->id)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $this->tokens->forUser($u);
            $this->audit->log('Usuario', $u->code, 'desactivar', 'activo', trim((string) $reason), actor: $actor);
        });

        return ['user' => $this->row($u->fresh(['roles', 'sede'])), 'warnings' => $warnings, 'message' => "{$u->name} quedó inactivo; se conservan su historial y registros."];
    }

    /** §12-2: reactivar también valida el límite del plan. Sin contraseña aún (nunca aceptó), vuelve a pendiente con nueva invitación. */
    public function activate(User $u, User $actor): array
    {
        if ($u->status !== User::INACTIVE) {
            throw ApiException::rule('USR-STATE', 'Solo se activan usuarios inactivos.');
        }

        return DB::transaction(function () use ($u, $actor) {
            $this->lockSeats();
            $pending = $u->password === null;
            $u->forceFill(['status' => $pending ? User::PENDING : User::ACTIVE, 'deactivated_at' => null, 'deactivated_reason' => null, 'updated_by' => $actor->id,
                'lock_version' => (int) $u->lock_version + 1])->save();
            if ($pending) {
                $this->issueInvitation($u, (bool) $u->mfa_required, $actor);
            }
            $this->audit->log('Usuario', $u->code, 'activar', 'inactivo', $pending ? 'pendiente (invitación reenviada)' : 'activo', actor: $actor);

            return $this->row($u->fresh(['roles', 'sede']));
        });
    }

    /** USR-BR-10: motivo obligatorio; obliga a re-enrolar y notifica al usuario. */
    public function resetMfa(User $u, ?string $reason, User $actor): array
    {
        if (trim((string) $reason) === '') {
            throw ApiException::invalid('reason', 'Indique el motivo del reseteo de MFA.');
        }
        DB::transaction(function () use ($u, $reason, $actor) {
            MfaFactor::where('user_id', $u->id)->delete();
            $u->forceFill(['mfa_enabled' => false, 'mfa_required' => true, 'updated_by' => $actor->id])->save();
            $this->tokens->forUser($u);
            $this->audit->log('Usuario', $u->code, 'resetear_mfa', null, trim((string) $reason), actor: $actor);
            $this->notify->push('seguridad.mfa_reset', 'email', $u, 'Se restableció su verificación en dos pasos',
                'Un administrador restableció su MFA. En su próximo ingreso deberá enrolar de nuevo su app autenticadora.');
        });

        return $this->row($u->fresh(['roles', 'sede']));
    }

    public function resendInvitation(User $u, User $actor): array
    {
        if ($u->status !== User::PENDING) {
            throw ApiException::rule('USR-STATE', 'Solo se reenvía la invitación de usuarios pendientes.');
        }

        return DB::transaction(function () use ($u, $actor) {
            $previous = Invitation::where('user_id', $u->id)->latest('created_at')->first();
            $link = $this->issueInvitation($u, $previous?->require_mfa ?? (bool) $u->mfa_required, $actor, ((int) $previous?->resent_count) + 1);
            $this->audit->log('Usuario', $u->code, 'reenviar_invitacion', null, 'nuevo enlace válido 72 h', actor: $actor);

            return ['message' => "Invitación reenviada a {$u->email}. El enlace anterior quedó invalidado.", 'invitation_url' => app()->isProduction() ? null : $link];
        });
    }

    /** USR-BR-11: token de un solo uso (solo su hash en BD); invalida los anteriores. */
    private function issueInvitation(User $u, bool $requireMfa, User|PlatformUser $actor, int $resent = 0): string
    {
        Invitation::where('user_id', $u->id)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $token = Str::random(48);
        Invitation::create(['user_id' => $u->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(Invitation::TTL_HOURS),
            'require_mfa' => $requireMfa, 'invited_by' => $actor instanceof User ? $actor->id : null, 'resent_count' => $resent, 'created_at' => now()]);
        $link = rtrim((string) config('turismo.frontend_url'), '/').'/invitacion/'.$token;
        $tenant = $this->tenantRow();
        $this->notify->push('usuario.invitado', 'email', $u->email, "Invitación a {$tenant->trade_name}",
            "Hola {$u->name},\n{$actor->name} le invitó a {$tenant->trade_name}. Defina su contraseña aquí (vence en 72 horas):\n{$link}",
            ['related_type' => 'user', 'related_id' => $u->id, 'link' => $link]);

        return $link;
    }

    /** USR-BR-02 con `SELECT … FOR UPDATE` sobre el tenant: dos invitaciones concurrentes no superan el límite. */
    private function lockSeats(): void
    {
        $tenant = Tenant::whereKey($this->context->id())->lockForUpdate()->with('plan')->first();
        $max = $tenant?->plan?->max_users;
        if ($max !== null && $this->usedSeats() >= $max) {
            throw ApiException::rule('USR-BR-02', "Plan/entitlements: el plan {$tenant->plan->name} permite {$max} usuarios y ya tiene {$this->usedSeats()}. Desactive usuarios o cambie de plan.");
        }
    }

    public function usedSeats(): int
    {
        return User::where('status', '<>', User::INACTIVE)->count();
    }

    private function assertAnotherAdmin(User $u): void
    {
        $others = User::where('status', User::ACTIVE)->whereKeyNot($u->id)->whereHas('roles', fn ($q) => $q->where('name', 'tenant_admin'))->count();
        if ($others === 0) {
            throw ApiException::rule('USR-BR-05', 'El tenant debe conservar al menos un Tenant Admin activo.');
        }
    }

    /** @return list<string> USR-BR-08 */
    private function openRecords(User $u): array
    {
        $warnings = [];
        $opps = DB::table('crm_opportunities')->where('owner_id', $u->id)->whereNull('deleted_at')->whereNotIn('stage', ['ganada', 'perdida'])->count();
        $acts = DB::table('crm_activities')->where('owner_id', $u->id)->whereNotIn('status', ['completada', 'cancelada'])->count();
        if ($opps) {
            $warnings[] = "Tiene {$opps} oportunidad(es) abierta(s): reasígnelas desde CRM.";
        }
        if ($acts) {
            $warnings[] = "Tiene {$acts} actividad(es) pendiente(s).";
        }

        return $warnings;
    }

    private function role(string $name): Role
    {
        return Role::where('tenant_id', $this->context->id())->where('name', $name)->first()
            ?? throw ApiException::invalid('role', 'Rol inexistente en el tenant.');
    }

    private function nextCode(): string
    {
        $max = User::withTrashed()->where('code', '~', '^U[0-9]+$')->get(['code'])->map(fn ($u) => (int) substr($u->code, 1))->max() ?? 0;

        return sprintf('U%02d', $max + 1);
    }

    private function tenantRow(): Tenant
    {
        return Tenant::with('plan')->findOrFail($this->context->id());
    }

    private function assertVersion(User $u, ?string $ifMatch): void
    {
        if ($ifMatch === null || $ifMatch === '') {
            throw new ApiException(428, 'PRECONDITION_REQUIRED', 'SEC-BR-17', 'Este recurso exige el header If-Match con su lock_version');
        }
        if ((int) trim(preg_replace('/^W\//', '', $ifMatch), '"') !== (int) $u->lock_version) {
            throw ApiException::conflict('STALE_VERSION', 'El registro fue modificado por otro usuario; recargue e intente de nuevo');
        }
    }

    /** @return Collection<int, User> */
    public function managers(): Collection
    {
        return User::where('status', User::ACTIVE)->whereHas('roles', fn ($q) => $q->whereIn('name', ['tenant_admin', 'manager', 'sales', 'operations', 'finance']))->orderBy('name')->get();
    }
}
