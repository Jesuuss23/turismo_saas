<?php

namespace App\Notifications\Channels;

use App\Models\Sys\NotifMessage;
use Illuminate\Support\Facades\Log;

/** WhatsApp en sandbox (spec 01 §8.3): registra el envío; el adapter real llega con Integraciones. */
class WhatsAppSandboxSender implements ChannelSender
{
    public function send(NotifMessage $message): void
    {
        Log::info('WhatsApp (sandbox)', ['to' => $message->recipient, 'subject' => $message->subject]);
    }
}
