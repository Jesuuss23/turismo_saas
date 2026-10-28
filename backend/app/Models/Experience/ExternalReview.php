<?php

namespace App\Models\Experience;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Reseña importada de una plataforma externa; el texto es inmutable (RV-01). */
class ExternalReview extends TenantModel
{
    protected $table = 'channel_external_reviews';

    protected function casts(): array
    {
        return ['review_date' => 'date', 'rating' => 'integer', 'replied_at' => 'datetime', 'is_example' => 'boolean', 'raw' => 'array'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ReviewSource::class, 'source_id');
    }
}
