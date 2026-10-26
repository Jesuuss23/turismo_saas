<?php

namespace App\Models\Quotes;

use App\Models\TenantModel;

/** Contrapropuesta del cliente registrada por el asesor (PROC-024). */
class QuoteNegotiation extends TenantModel
{
    public const CHANNELS = ['Llamada', 'Reunión', 'Email', 'WhatsApp'];

    public const AUDIT_COLUMNS = ['created_by'];

    public $timestamps = false;

    protected $table = 'quote_negotiations';

    protected function casts(): array
    {
        return ['created_version_no' => 'integer', 'created_at' => 'datetime'];
    }
}
