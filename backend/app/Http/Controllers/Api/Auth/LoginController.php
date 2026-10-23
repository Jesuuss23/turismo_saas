<?php

namespace App\Http\Controllers\Api\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\MeResource;
use App\Models\Iam\User;
use App\Models\Sanctum\PersonalAccessToken;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\Auth\MfaService;
use App\Services\Auth\TokenIssuer;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * PROC-062: credenciales → token. Si el usuario requiere MFA, el token solo tiene
 * la ability mfa:verify hasta completar el segundo paso.
 */
class LoginController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly TokenIssuer $tokens,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:255'],
            'tenant_slug' => ['nullable', 'string', 'max:60'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $tenant = $this->tenantFor($request, $data['tenant_slug'] ?? null);
        if ($tenant === null) {
            $this->audit->isolated('Sesión', $data['email'], 'login_fallido', null, 'tenant inexistente');

            throw $this->invalidCredentials();
        }

        $this->context->set($tenant);

        $user = User::whereRaw('lower(email) = ?', [mb_strtolower($data['email'])])->first();
        if ($user === null || ! $user->isActive() || ! Hash::check($data['password'], (string) $user->password)) {
            $this->audit->isolated('Sesión', $data['email'], 'login_fallido', null, $user && ! $user->isActive() ? "usuario {$user->status}" : 'credenciales inválidas');

            throw $this->invalidCredentials();
        }

        if (! $tenant->isActive()) {
            throw ApiException::tenantSuspended();
        }

        $mfaPending = $user->requiresMfa();
        $token = $this->tokens->issue($user, $mfaPending, $data['device_name'] ?? null, $request->ip());

        if ($mfaPending) {
            return response()->json([
                'mfa_required' => true,
                'mfa_status' => $user->mfaFactor()->whereNotNull('confirmed_at')->exists() ? 'verify' : 'enroll',
                'token' => $token->plainTextToken,
                'expires_at' => $token->accessToken->expires_at,
            ]);
        }

        $this->completeLogin($user, $request, 'OK');

        return $this->tokenResponse($user, $token);
    }

    public function verifyMfa(Request $request, MfaService $mfa): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        /** @var User $user */
        $user = $request->user();
        /** @var PersonalAccessToken $pending */
        $pending = $user->currentAccessToken();

        if (! $pending->isMfaPending()) {
            throw new ApiException(409, 'MFA_ALREADY_VERIFIED', 'SEC-BR-08', 'Esta sesión ya completó la verificación MFA');
        }
        if (! $mfa->verify($user, $data['code'])) {
            $this->audit->isolated('Sesión', $user->email, 'login_fallido', null, 'código MFA inválido');

            throw new ApiException(422, 'MFA_INVALID_CODE', 'SEC-BR-08', 'El código de verificación no es válido');
        }

        $pending->delete();
        $token = $this->tokens->issue($user, false, $pending->device_name, $request->ip());
        $this->completeLogin($user, $request, 'MFA OK');

        return $this->tokenResponse($user, $token);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        $this->audit->log('Sesión', $request->user()->email, 'logout');

        return response()->json(['message' => 'Sesión cerrada']);
    }

    public function completeLogin(User $user, Request $request, string $result): void
    {
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $this->audit->log('Sesión', $user->email, 'login', '', $result, actor: $user);
    }

    public function tokenResponse(User $user, $token): JsonResponse
    {
        $user->load('roles', 'sede', 'tenant');

        return response()->json([
            'mfa_required' => false,
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at,
            'user' => new MeResource($user),
        ]);
    }

    /** Tenant por subdominio {slug}.dominio; si no hay subdominio, por tenant_slug. */
    private function tenantFor(Request $request, ?string $slug): ?Tenant
    {
        $base = config('turismo.tenant_base_domain');
        $host = $request->getHost();
        $hostSlug = str_ends_with($host, '.'.$base) ? substr($host, 0, -strlen('.'.$base)) : null;

        if ($hostSlug !== null && $slug !== null && $hostSlug !== $slug) {
            return null;
        }

        $slug = $hostSlug ?? $slug;

        return $slug ? Tenant::where('slug', $slug)->first() : null;
    }

    private function invalidCredentials(): ApiException
    {
        return new ApiException(401, 'INVALID_CREDENTIALS', 'PROC-062', 'Correo o contraseña incorrectos');
    }
}
