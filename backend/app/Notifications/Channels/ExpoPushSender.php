<?php

namespace App\Notifications\Channels;

use App\Models\Ops\MobileDevice;
use App\Models\Sys\NotifMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Push (SendExpoPush, spec 05 §8.3): Expo Push API a los dispositivos activos del usuario. Sin EXPO_ACCESS_TOKEN queda
 * en sandbox (registra el envío). El registro en notif_messages sirve además de bandeja (campana / alertas del pasajero).
 * Tokens inválidos (`DeviceNotRegistered`) se anulan.
 */
class ExpoPushSender implements ChannelSender
{
    public function send(NotifMessage $message): void
    {
        $devices = $message->user_id
            ? MobileDevice::where('user_id', $message->user_id)->whereNull('revoked_at')->whereNotNull('expo_push_token')->get()
            : collect();
        $token = config('turismo.expo_access_token');
        if (! $token || $devices->isEmpty()) {
            Log::info('Push (sandbox)', ['to' => $message->recipient, 'subject' => $message->subject, 'devices' => $devices->count()]);

            return;
        }
        $response = Http::withToken($token)->acceptJson()->post('https://exp.host/--/api/v2/push/send', $devices->map(fn (MobileDevice $d) => [
            'to' => $d->expo_push_token, 'title' => $message->subject, 'body' => $message->body, 'data' => $message->data ?? [],
        ])->values()->all())->throw();
        foreach ((array) $response->json('data') as $i => $ticket) {
            if (($ticket['details']['error'] ?? null) === 'DeviceNotRegistered' && isset($devices[$i])) {
                $devices[$i]->forceFill(['expo_push_token' => null])->save();
            }
        }
    }
}
