<?php

namespace App\Models\Experience;

use App\Models\TenantModel;

/** Conector de reseñas externas; el secreto va cifrado y nunca se serializa (RV-03). */
class ReviewSource extends TenantModel
{
    protected $table = 'integration_review_sources';

    protected $hidden = ['secret_encrypted'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'can_reply' => 'boolean', 'supports_invites' => 'boolean', 'rating' => 'decimal:2', 'last_sync_at' => 'datetime', 'connected_at' => 'datetime', 'contract_ack_at' => 'datetime', 'secret_encrypted' => 'encrypted'];
    }
}
