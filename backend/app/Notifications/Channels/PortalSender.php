<?php

namespace App\Notifications\Channels;

use App\Models\Sys\NotifMessage;

/**
 * Canal Portal: el registro en notif_messages es la notificación (campana).
 * El broadcast por Reverb a private-tenant.{t}.user.{u} se conecta cuando se configure Reverb.
 */
class PortalSender implements ChannelSender
{
    public function send(NotifMessage $message): void {}
}
