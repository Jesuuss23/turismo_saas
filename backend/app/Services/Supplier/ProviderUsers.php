<?php

namespace App\Services\Supplier;

use App\Exceptions\ApiException;
use App\Models\Catalog\Provider;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Notify;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Usuarios de la extranet de un proveedor (spec 06 §6 contraparte, §12-5): Catálogo › Proveedores invita con su sub-rol. */
class ProviderUsers
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    public function invite(Provider $provider, array $data, User $actor): User
    {
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));
        $role = (string) ($data['provider_role'] ?? 'admin');
        if ($name === '') {
            throw ApiException::invalid('name', 'Indique el nombre del usuario.');
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ApiException::invalid('email', 'Indique un email válido.');
        }
        if (! isset(SupplierAccess::ROLES[$role])) {
            throw ApiException::invalid('provider_role', 'Elija el perfil dentro del proveedor.');
        }
        if (User::withTrashed()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ApiException::rule('SEC-BR-12', "Ya existe un usuario con el email {$email}.");
        }

        return DB::transaction(function () use ($provider, $actor, $email, $name, $role) {
            $user = User::create([
                'code' => 'PRV-U'.Str::upper(Str::random(5)), 'name' => $name, 'email' => $email, 'password' => null, 'sede_id' => $actor->sede_id,
                'status' => User::PENDING, 'provider_id' => $provider->id, 'provider_role' => $role, 'invited_at' => now(), 'invited_by' => $actor->id,
            ]);
            $user->assignRole('provider');
            $this->audit->log('Usuario', $user->code, 'invitar', null, "{$name} · {$provider->name} · ".SupplierAccess::ROLES[$role], actor: $actor);
            $this->notify->push('usuario.invitacion', 'email', $email, "Invitación a la extranet de proveedores · {$provider->name}",
                'Active su cuenta para responder solicitudes, rooming lists, vouchers y pagos de la agencia.', ['related_type' => 'provider', 'related_id' => $provider->id]);

            return $user->fresh();
        });
    }

    public static function row(User $u): array
    {
        return ['id' => $u->id, 'code' => $u->code, 'name' => $u->name, 'email' => $u->email, 'status' => $u->status,
            'provider_role' => $u->provider_role ?? 'admin', 'provider_role_label' => SupplierAccess::ROLES[$u->provider_role ?? 'admin'],
            'last_login_at' => $u->last_login_at?->toIso8601String(), 'invited_at' => $u->invited_at?->toIso8601String()];
    }
}
