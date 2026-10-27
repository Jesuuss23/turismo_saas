<?php

namespace App\Services\Admin;

use App\Models\Admin\IntegrationAdapter;
use App\Models\Admin\IntegrationError;
use App\Models\Sys\NotifMessage;

/** Registro de errores de jobs, notificaciones e integraciones (spec 09 §3.15, §4.6). */
final class IntegrationErrors
{
    public const CHANNEL_ADAPTER = ['email' => 'INT-03', 'whatsapp' => 'INT-04', 'push' => null, 'portal' => null];

    /** Envío con error permanente: un error por mensaje, directo a fallo definitivo (INT-BR-05). */
    public static function permanentNotification(NotifMessage $message, string $error): IntegrationError
    {
        $code = $message->displayCode();
        $adapter = self::CHANNEL_ADAPTER[$message->channel] ?? null;
        $target = $adapter ? (IntegrationAdapter::where('code', $adapter)->value('name') ?? $message->channel) : ucfirst($message->channel);

        return IntegrationError::updateOrCreate(['source' => 'notification', 'ref_code' => $code], [
            'target' => "{$target} · {$message->event}", 'error' => $error, 'error_class' => 'permanente', 'attempt' => $message->retries,
            'max_attempts' => 5, 'next_retry_at' => null, 'status' => 'fallo_definitivo',
        ]);
    }

    /** INT-BR-04: backoff exponencial base × 2^intento (±20 % de jitter). */
    public static function backoffSeconds(int $base, int $attempt, bool $jitter = true): int
    {
        $s = $base * (2 ** max(0, $attempt - 1));

        return $jitter ? (int) round($s * (1 + (mt_rand(-20, 20) / 100))) : $s;
    }
}
