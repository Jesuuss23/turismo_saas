<?php

namespace App\Notifications\Channels;

use App\Models\Sys\NotifMessage;
use Illuminate\Support\Facades\Mail;

class MailSender implements ChannelSender
{
    public function send(NotifMessage $message): void
    {
        Mail::raw((string) $message->body, function ($mail) use ($message) {
            $mail->to($message->recipient)->subject($message->subject);
        });
    }
}
