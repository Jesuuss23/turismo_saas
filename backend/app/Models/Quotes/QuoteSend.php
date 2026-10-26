<?php

namespace App\Models\Quotes;

use App\Models\TenantModel;

/** Envío de la cotización por canal (PROC-023). */
class QuoteSend extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    public $timestamps = false;

    protected $table = 'quote_sends';

    protected function casts(): array
    {
        return ['version_no' => 'integer', 'sent_at' => 'datetime'];
    }
}
