<?php

namespace App\Models\Channel;

use App\Models\TenantModel;

/** Lista de precios B2B (spec 08 §3.2). */
class B2bPriceList extends TenantModel
{
    protected $table = 'channel_b2b_price_lists';
}
