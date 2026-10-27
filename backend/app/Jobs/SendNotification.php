<?php

namespace App\Jobs;

use App\Models\Sys\NotifMessage;
use App\Notifications\Channels\PermanentDeliveryFailure;
use App\Services\Admin\IntegrationErrors;
use App\Services\Notify;
use App\Tenancy\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendNotification implements ShouldQueue
{
    use Queueable, TenantAware;

    public int $tries = 5;

    public function __construct(public string $messageId)
    {
        $this->onQueue('notifications');
        $this->captureTenant();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(Notify $notify): void
    {
        $message = NotifMessage::findOrFail($this->messageId);

        try {
            $notify->sender($message->channel)->send($message);
            $message->forceFill(['status' => 'enviado', 'sent_at' => now(), 'last_error' => null])->save();
        } catch (PermanentDeliveryFailure $e) {
            // NTF-BR-07 / INT-BR-05: no se reintenta; queda como fallo definitivo para resolver por canal alterno.
            $message->forceFill(['status' => 'fallido', 'retries' => $message->retries + 1, 'last_error' => $e->getMessage()])->save();
            IntegrationErrors::permanentNotification($message, $e->getMessage());
        } catch (Throwable $e) {
            $message->forceFill(['status' => 'fallido', 'retries' => $message->retries + 1, 'last_error' => $e->getMessage()])->save();
            throw $e;
        }
    }
}
