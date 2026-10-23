<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientContact extends CrmModel
{
    protected $table = 'crm_client_contacts';

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
