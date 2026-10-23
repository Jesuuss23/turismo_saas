<?php

namespace App\Notifications\Channels;

use App\Models\Sys\NotifMessage;

/** Adapter de canal (MailSender, MessagingChannel, push, portal). Lanza excepción si falla. */
interface ChannelSender
{
    public function send(NotifMessage $message): void;
}
