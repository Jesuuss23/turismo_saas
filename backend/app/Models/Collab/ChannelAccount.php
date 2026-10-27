<?php

namespace App\Models\Collab;

use App\Models\TenantModel;

/** Cuenta de canal de la bandeja (spec 10 §3.10): credenciales y secreto del webhook cifrados. */
class ChannelAccount extends TenantModel
{
    protected $table = 'collab_channel_accounts';

    protected $hidden = ['credentials_encrypted', 'webhook_secret_encrypted'];

    protected function casts(): array
    {
        return ['credentials_encrypted' => 'encrypted:array', 'webhook_secret_encrypted' => 'encrypted'];
    }
}
