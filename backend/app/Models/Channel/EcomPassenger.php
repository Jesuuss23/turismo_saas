<?php

namespace App\Models\Channel;

use App\Models\TenantModel;

/** Pasajero del pedido web (spec 08 §3.7). */
class EcomPassenger extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'channel_ecom_passengers';
}
