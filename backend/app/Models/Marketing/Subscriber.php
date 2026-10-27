<?php

namespace App\Models\Marketing;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Suscriptor del newsletter (spec 14 §3.1): contacto que aún no es lead ni cliente. */
class Subscriber extends TenantModel
{
    use SoftDeletes;

    protected $table = 'mkt_contacts_subscribers';

    protected function casts(): array
    {
        return ['double_opt_in_at' => 'datetime', 'birth_date' => 'date'];
    }
}
