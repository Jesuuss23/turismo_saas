<?php

namespace App\Services\Auth;

use App\Models\Iam\MfaFactor;
use App\Models\Iam\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** TOTP con anti-replay y 8 códigos de recuperación hasheados (§3.3). */
class MfaService
{
    private const WINDOW = 1;

    public function __construct(private readonly Google2FA $totp) {}

    /** @return array{secret: string, otpauth_url: string} */
    public function enroll(User $user): array
    {
        $secret = $this->totp->generateSecretKey(32);

        MfaFactor::where('user_id', $user->getKey())->delete();
        MfaFactor::create([
            'user_id' => $user->getKey(),
            'type' => 'totp',
            'secret' => $secret,
            'recovery_codes' => [],
            'created_at' => now(),
        ]);

        return [
            'secret' => $secret,
            'otpauth_url' => $this->totp->getQRCodeUrl(config('app.name').' · '.$user->tenant?->slug, $user->email, $secret),
        ];
    }

    /** Confirma el enrolamiento. Devuelve los códigos de recuperación en claro (solo esta vez) o null si el código no es válido. */
    public function confirm(User $user, string $code): ?array
    {
        $factor = $user->mfaFactor()->first();
        if ($factor === null || $factor->isConfirmed() || ! $this->checkTotp($factor, $code)) {
            return null;
        }

        $codes = collect(range(1, 8))->map(fn () => Str::upper(Str::random(5).'-'.Str::random(5)))->all();
        $factor->forceFill([
            'confirmed_at' => now(),
            'recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes),
        ])->save();
        $user->forceFill(['mfa_enabled' => true, 'mfa_required' => false])->save();

        return $codes;
    }

    /** Verifica un código TOTP o de recuperación (este último se consume). */
    public function verify(User $user, string $code): bool
    {
        $factor = $user->mfaFactor()->first();
        if ($factor === null || ! $factor->isConfirmed()) {
            return false;
        }

        if (preg_match('/^\d{6}$/', $code)) {
            return $this->checkTotp($factor, $code);
        }

        $codes = $factor->recovery_codes;
        foreach ($codes as $i => $hash) {
            if (Hash::check(Str::upper($code), $hash)) {
                unset($codes[$i]);
                $factor->forceFill(['recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    public function disable(User $user): void
    {
        MfaFactor::where('user_id', $user->getKey())->delete();
        $user->forceFill(['mfa_enabled' => false])->save();
    }

    /** Para usuarios de plataforma: devuelve el nuevo paso usado o null. */
    public function checkSecret(string $secret, string $code, ?int $lastStep): ?int
    {
        // Con oldTimestamp null la librería devuelve true en vez del paso; 0 fuerza que devuelva el paso.
        $step = $this->totp->verifyKeyNewer($secret, $code, $lastStep ?? 0, self::WINDOW);

        return $step === false ? null : (int) $step;
    }

    private function checkTotp(MfaFactor $factor, string $code): bool
    {
        $step = $this->checkSecret($factor->secret, $code, $factor->last_used_step);
        if ($step === null) {
            return false;
        }
        $factor->forceFill(['last_used_step' => $step])->save();

        return true;
    }
}
