<?php

namespace App\Services;

use App\Jobs\SendNotification;
use App\Models\Iam\User;
use App\Models\Sys\NotifMessage;
use App\Notifications\Channels\ChannelSender;
use App\Notifications\Channels\ExpoPushSender;
use App\Notifications\Channels\MailSender;
use App\Notifications\Channels\PortalSender;
use App\Notifications\Channels\WhatsAppSandboxSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Servicio Notify (§8.1), equivalente a `Notify.push`. Todo ocurre después del commit y
 * protegido: una notificación fallida nunca revierte la transacción de negocio (SEC-BR-22).
 * Reglas por evento, plantillas y momentos de envío llegan con el módulo 09.
 */
class Notify
{
    /** @var array<string, class-string<ChannelSender>> */
    protected array $senders = [
        'portal' => PortalSender::class,
        'email' => MailSender::class,
        'whatsapp' => WhatsAppSandboxSender::class,
        'push' => ExpoPushSender::class,
    ];

    public function push(string $event, string $channel, User|string $to, string $subject, ?string $body = null, array $data = []): void
    {
        DB::afterCommit(function () use ($event, $channel, $to, $subject, $body, $data) {
            try {
                $message = NotifMessage::create([
                    'event' => $event,
                    'channel' => $channel,
                    'user_id' => $to instanceof User ? $to->getKey() : null,
                    'recipient' => $to instanceof User ? ($channel === 'email' ? $to->email : $to->name) : $to,
                    'subject' => $subject,
                    'body' => $body,
                    'data' => $data,
                    'status' => 'pendiente',
                    // Vínculos por FK para filtrar (p. ej. Comunicaciones del 360, spec 01 §11-27).
                    'client_id' => $data['client_id'] ?? null,
                    'related_type' => $data['related_type'] ?? null,
                    'related_id' => $data['related_id'] ?? null,
                ]);

                dispatch(new SendNotification($message->getKey()))->afterCommit();
            } catch (Throwable $e) {
                Log::error('Notify: no se pudo encolar la notificación', ['event' => $event, 'error' => $e->getMessage()]);
            }
        });
    }

    public function sender(string $channel): ChannelSender
    {
        $class = $this->senders[$channel] ?? throw new InvalidArgumentException("Canal sin adapter: {$channel}");

        return app($class);
    }

    public function extend(string $channel, string $senderClass): void
    {
        $this->senders[$channel] = $senderClass;
    }
}
