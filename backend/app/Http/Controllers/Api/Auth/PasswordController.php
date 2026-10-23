<?php

namespace App\Http\Controllers\Api\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Iam\User;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\Auth\TokenRevoker;
use App\Services\Notify;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/** Recuperación de contraseña con enlace de un solo uso (60 min). No revela si el correo existe. */
class PasswordController extends Controller
{
    private const TTL_MINUTES = 60;

    public function __construct(private readonly TenantContext $context) {}

    public function forgot(Request $request, Notify $notify): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'tenant_slug' => ['required', 'string']]);

        if ($user = $this->findUser($data['tenant_slug'], $data['email'])) {
            $plain = Str::random(64);
            DB::table('iam_password_reset_tokens')->upsert(
                ['tenant_id' => $user->tenant_id, 'email' => mb_strtolower($user->email), 'token' => Hash::make($plain), 'created_at' => now()],
                ['tenant_id', 'email'],
                ['token', 'created_at'],
            );
            $url = rtrim((string) config('turismo.frontend_url'), '/')."/restablecer?token={$plain}&email=".urlencode($user->email)."&tenant={$data['tenant_slug']}";
            $notify->push('auth.password_reset', 'email', $user, 'Restablecer contraseña', "Use este enlace (válido 60 minutos): {$url}");
        }

        return response()->json(['message' => 'Si el correo existe, enviamos un enlace para restablecer la contraseña'], 202);
    }

    public function reset(Request $request, TokenRevoker $revoker, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'tenant_slug' => ['required', 'string'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(10)->mixedCase()->numbers()],
        ]);

        $user = $this->findUser($data['tenant_slug'], $data['email']);
        $row = $user ? DB::table('iam_password_reset_tokens')->where('email', mb_strtolower($user->email))->first() : null;

        if (! $row || now()->subMinutes(self::TTL_MINUTES)->greaterThan($row->created_at) || ! Hash::check($data['token'], $row->token)) {
            throw new ApiException(422, 'INVALID_RESET_TOKEN', 'PROC-062', 'El enlace no es válido o expiró');
        }

        DB::transaction(function () use ($user, $data, $revoker, $audit) {
            $user->forceFill(['password' => $data['password']])->save();
            DB::table('iam_password_reset_tokens')->where('email', mb_strtolower($user->email))->delete();
            $revoker->forUser($user);
            $audit->log('Usuario', $user->code ?? $user->email, 'restablecer_contrasena', null, null, actor: $user);
        });

        return response()->json(['message' => 'Contraseña actualizada']);
    }

    private function findUser(string $slug, string $email): ?User
    {
        $tenant = Tenant::where('slug', $slug)->first();
        if (! $tenant?->isActive()) {
            return null;
        }
        $this->context->set($tenant);

        return User::whereRaw('lower(email) = ?', [mb_strtolower($email)])->where('status', User::ACTIVE)->first();
    }
}
