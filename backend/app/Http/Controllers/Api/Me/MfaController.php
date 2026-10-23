<?php

namespace App\Http\Controllers\Api\Me;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Controller;
use App\Models\Iam\User;
use App\Models\Sanctum\PersonalAccessToken;
use App\Services\AuditLogger;
use App\Services\Auth\MfaService;
use App\Services\Auth\TokenIssuer;
use App\Services\Auth\TokenRevoker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Enrolamiento TOTP. Accesible también con el token del paso MFA (primer ingreso con MFA obligatorio). */
class MfaController extends Controller
{
    public function __construct(private readonly MfaService $mfa) {}

    public function enroll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->mfaFactor()->whereNotNull('confirmed_at')->exists()) {
            throw new ApiException(409, 'MFA_ALREADY_ENABLED', 'SEC-BR-08', 'MFA ya está habilitado; desactívelo antes de enrolar otro dispositivo');
        }

        return response()->json($this->mfa->enroll($user));
    }

    public function confirm(Request $request, TokenIssuer $tokens, LoginController $login): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        /** @var User $user */
        $user = $request->user();

        $codes = $this->mfa->confirm($user, $data['code']);
        if ($codes === null) {
            throw new ApiException(422, 'MFA_INVALID_CODE', 'SEC-BR-08', 'El código de verificación no es válido');
        }

        $response = ['mfa_enabled' => true, 'recovery_codes' => $codes];

        /** @var PersonalAccessToken $current */
        $current = $user->currentAccessToken();
        if ($current->isMfaPending()) {
            // Primer ingreso: al confirmar queda verificado y recibe el token completo.
            $current->delete();
            $token = $tokens->issue($user, false, $current->device_name, $request->ip());
            $login->completeLogin($user, $request, 'MFA OK');
            $response += $login->tokenResponse($user, $token)->getData(true);
        }

        return response()->json($response);
    }

    public function destroy(Request $request, TokenRevoker $revoker, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        /** @var User $user */
        $user = $request->user();

        if ($user->tenant->requiresMfaForInternalRoles() && ! $user->isExternal()) {
            throw ApiException::forbidden('SEC-BR-08', 'La política de la agencia exige MFA para su rol');
        }
        if (! $this->mfa->verify($user, $data['code'])) {
            throw new ApiException(422, 'MFA_INVALID_CODE', 'SEC-BR-08', 'El código de verificación no es válido');
        }

        $this->mfa->disable($user);
        $revoker->forUser($user, $user->currentAccessToken()->getKey());
        $audit->log('Usuario', $user->code ?? $user->email, 'desactivar_mfa', 'habilitado', 'no_habilitado');

        return response()->json(['mfa_enabled' => false]);
    }
}
