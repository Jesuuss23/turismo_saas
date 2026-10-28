<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Página pública de un destino por idioma (spec 15 §3.11). */
class DestinationContent extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'catalog_destination_contents';

    protected function casts(): array
    {
        return ['faqs' => 'array', 'featured' => 'boolean'];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class, 'destination_id');
    }
}
