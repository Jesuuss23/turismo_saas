<?php

namespace App\Models\Quotes;

use App\Models\TenantModel;

/** Apertura del enlace o vista registrada manualmente (QTE-BR-22). */
class QuoteView extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    public $timestamps = false;

    protected $table = 'quote_views';

    protected function casts(): array
    {
        return ['version_no' => 'integer', 'viewed_at' => 'datetime'];
    }
}
