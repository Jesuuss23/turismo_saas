<?php

namespace App\Services;

use App\Jobs\SendNotification;
use App\Models\Admin\NotifRule;
use App\Models\Admin\NotifTemplate;
use App\Models\Iam\User;
use App\Models\Sys\NotifMessage;
use App\Notifications\Channels\ChannelSender;
use App\Notifications\Channels\ExpoPushSender;
use App\Notifications\Channels\MailSender;
use App\Notifications\Channels\PortalSender;
use App\Notifications\Channels\WhatsAppSandboxSender;
use App\Services\Admin\AdminCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Servicio Notify (§8.1), equivalente a `Notify.push`. Todo ocurre después del commit y
 * protegido: una notificación fallida nunca revierte la transacción de negocio (SEC-BR-22).
 * Los eventos configurables respetan la matriz del tenant y registran la plantilla vigente (spec 09 §8.3).
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
                // Matriz evento × canal del tenant (spec 09 §3.10): un canal apagado para un evento configurable no se envía.
                $rule = isset(AdminCatalog::EVENTS[$event]) ? NotifRule::where('event_code', $event)->first() : null;
                if ($rule && ! in_array($channel, $rule->activeChannels(), true) && empty($data['test'])) {
                    return;
                }
                $message = NotifMessage::create([
                    'event' => $event,
                    'channel' => $channel,
                    'user_id' => $to instanceof User ? $to->getKey() : null,
                    'recipient' => $to instanceof User ? match ($channel) {
                        'email' => $to->email, 'whatsapp' => $to->phone ?? $to->name, default => $to->name,
                    } : $to,
                    'template_id' => $channel === 'portal' || ! isset(AdminCatalog::EVENTS[$event]) ? null : NotifTemplate::current($event, $channel)?->id,
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
