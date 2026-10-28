<?php

namespace App\Models\Experience;

use App\Models\Bookings\Reservation;
use App\Models\Catalog\Product;
use App\Models\Crm\Client;
use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Reseña propia verificada (spec 18 §3.1): solo de pasajeros con viaje realizado; el texto del pasajero no se edita. */
class OwnReview extends TenantModel
{
    use OptimisticLock, SoftDeletes;

    protected $table = 'crm_own_reviews';

    public const STATUSES = ['pendiente' => 'Pendiente', 'publicada' => 'Publicada', 'rechazada' => 'Rechazada', 'oculta' => 'Oculta'];

    /** Cómo lo ve el pasajero (§7 U15). */
    public const PAX_LABELS = ['pendiente' => 'En revisión', 'publicada' => 'Publicada', 'rechazada' => 'No publicada', 'oculta' => 'Solo para la agencia'];

    protected function casts(): array
    {
        return ['travel_date' => 'date', 'stars' => 'integer', 'subratings' => 'array', 'photos' => 'array', 'flags' => 'array', 'verified' => 'boolean',
            'consent_publish' => 'boolean', 'is_demo' => 'boolean', 'replied_at' => 'datetime', 'published_at' => 'datetime', 'helpful_count' => 'integer', 'lock_version' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function log(): HasMany
    {
        return $this->hasMany(OwnReviewLog::class, 'review_id')->orderBy('created_at');
    }
}
