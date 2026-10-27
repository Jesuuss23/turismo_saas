<?php

namespace App\Notifications\Channels;

use App\Models\Sys\NotifMessage;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp en sandbox (INT-04, spec 09 §4.5): registra el envío. Si se configuran los números registrados en el sandbox
 * (`turismo.whatsapp_sandbox_numbers`), cualquier otro destino falla de forma permanente con el código 131026 de Meta.
 */
class WhatsAppSandboxSender implements ChannelSender
{
    public function send(NotifMessage $message): void
    {
        $allowed = config('turismo.whatsapp_sandbox_numbers');
        if (is_array($allowed) && ! in_array($message->recipient, $allowed, true)) {
            throw new PermanentDeliveryFailure('Código 131026: número no registrado en el entorno sandbox');
        }
        Log::info('WhatsApp (sandbox)', ['to' => $message->recipient, 'subject' => $message->subject]);
    }
}
