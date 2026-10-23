<?php

namespace App\Http\Controllers\Api\Platform;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Iam\PlatformUser;
use App\Models\Sanctum\PersonalAccessToken;
use App\Security\RoleUx;
use App\Services\AuditLogger;
use App\Services\Auth\MfaService;
use App\Services\Auth\TokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/** Guard `platform` (SEC-BR-06): MFA siempre. */
class PlatformAuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly TokenIssuer $tokens) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        $user = PlatformUser::where('email', mb_strtolower($data['email']))->first();
        if (! $user || $user->status !== 'activo' || ! Hash::check($data['password'], $user->password)) {
            $this->audit->isolated('Sesión', $data['email'], 'login_fallido', null, 'plataforma: credenciales inválidas');

            throw new ApiException(401, 'INVALID_CREDENTIALS', 'PROC-062', 'Correo o contraseña incorrectos');
        }

        $token = $this->tokens->issue($user, true, null, $request->ip());

        return response()->json(['mfa_required' => true, 'mfa_status' => 'verify', 'token' => $token->plainTextToken]);
    }

    public function verifyMfa(Request $request, MfaService $mfa): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $user = $request->user();
        $pending = $user?->currentAccessToken();

        if (! $user instanceof PlatformUser || ! $pending instanceof PersonalAccessToken || ! $pending->isMfaPending()) {
            throw ApiException::forbidden('SEC-BR-06', 'fuera del alcance del rol');
        }

        $step = $mfa->checkSecret($user->mfa_secret, $data['code'], $user->mfa_last_used_step);
        if ($step === null) {
            $this->audit->isolated('Sesión', $user->email, 'login_fallido', null, 'plataforma: código MFA inválido', $user);

            throw new ApiException(422, 'MFA_INVALID_CODE', 'SEC-BR-08', 'El código de verificación no es válido');
        }

        $pending->delete();
        $user->forceFill(['mfa_last_used_step' => $step, 'last_login_at' => now()])->save();
        $token = $this->tokens->issue($user, false, null, $request->ip());
        $this->audit->log('Sesión', $user->email, 'login', '', 'MFA OK', actor: $user);

        return response()->json(['token' => $token->plainTextToken, 'user' => $this->present($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->present($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        $this->audit->log('Sesión', $request->user()->email, 'logout');

        return response()->json(['message' => 'Sesión cerrada']);
    }

    private function present(PlatformUser $user): array
    {
        $ux = RoleUx::for($user->roleName());

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => ['name' => $user->roleName(), 'display_name' => 'Super Admin SaaS'],
            'experience' => 'platform',
            'home' => $ux['home'],
            'routes' => $ux['routes'],
            'permissions' => $user->permissionNames(),
        ];
    }
}
