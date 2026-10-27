<?php

namespace App\Http\Controllers\Api\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Admin\Invitation;
use App\Models\Iam\User;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/**
 * Aceptar invitación (spec 09 §4.2, §6.2): pendiente → activo. El token resuelve tenant y usuario sin sesión;
 * el enrolamiento MFA ocurre en el primer inicio de sesión cuando la invitación lo exige.
 */
class InvitationController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(string $token): JsonResponse
    {
        [$invitation, $tenant] = $this->resolve($token);

        return response()->json(['data' => [
            'name' => $invitation->user->name, 'email' => $invitation->user->email, 'tenant' => $tenant->trade_name ?? $tenant->legal_name, 'tenant_slug' => $tenant->slug,
            'role' => $invitation->user->roles->first()?->display_name, 'require_mfa' => $invitation->require_mfa, 'expires_at' => $invitation->expires_at->toIso8601String(),
        ]]);
    }

    public function accept(Request $request, string $token, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'confirmed', Password::min(10)->mixedCase()->numbers()]],
            ['password.confirmed' => 'Las contraseñas no coinciden.']);
        [$invitation, $tenant] = $this->resolve($token);

        DB::transaction(function () use ($invitation, $data, $audit) {
            $user = $invitation->user;
            $user->forceFill(['password' => $data['password'], 'status' => User::ACTIVE, 'mfa_required' => $invitation->require_mfa])->save();
            $invitation->forceFill(['accepted_at' => now()])->save();
            $audit->log('Usuario', $user->code, 'activar', 'pendiente', 'invitación aceptada', actor: $user);
        });

        return response()->json(['message' => 'Cuenta activada. Inicie sesión'.($invitation->require_mfa ? ' y configure su segundo factor.' : '.'),
            'tenant_slug' => $tenant->slug, 'email' => $invitation->user->email]);
    }

    /** @return array{0: Invitation, 1: Tenant} */
    private function resolve(string $token): array
    {
        $row = DB::selectOne('SELECT tenant_id, user_id FROM iam_invitation_lookup(?)', [hash('sha256', $token)]);
        $tenant = $row ? Tenant::find($row->tenant_id) : null;
        if (! $tenant) {
            throw new ApiException(404, 'INVITATION_NOT_FOUND', 'PROC-002', 'La invitación no existe');
        }
        if (! $tenant->isActive()) {
            throw ApiException::tenantSuspended();
        }
        $this->context->set($tenant);
        $invitation = Invitation::with('user.roles')->where('token_hash', hash('sha256', $token))->firstOrFail();
        if (! $invitation->isUsable() || $invitation->user->status !== User::PENDING) {
            throw new ApiException(410, 'INVITATION_EXPIRED', 'PROC-002', $invitation->accepted_at ? 'La invitación ya fue utilizada' : 'La invitación expiró o fue revocada. Pida al administrador que la reenvíe.');
        }

        return [$invitation, $tenant];
    }
}
