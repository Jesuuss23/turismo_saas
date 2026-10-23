<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Encuesta NPS (spec 01 §3.9). Solo INSERT. */
class Survey extends CrmModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'crm_surveys';

    protected array $generated = ['category'];

    protected function casts(): array
    {
        return ['responded_at' => 'datetime', 'score' => 'integer'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
