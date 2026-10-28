<?php

namespace App\Models\Experience;

use App\Models\Bookings\Reservation;
use App\Models\Crm\Client;
use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Solicitud de reseña postviaje (spec 18 §3.3): enlace firmado de un solo uso a `/resena/{code}?t=`. */
class ReviewRequest extends TenantModel
{
    use OptimisticLock, SoftDeletes;

    protected $table = 'crm_review_requests';

    public const STATUSES = ['programada' => 'Programada', 'enviada' => 'Enviada', 'abierta' => 'Abierta', 'respondida' => 'Respondida', 'vencida' => 'Vencida', 'cancelada' => 'Cancelada'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'sent_at' => 'datetime', 'opened_at' => 'datetime', 'reminded_at' => 'datetime', 'expires_at' => 'datetime', 'is_demo' => 'boolean', 'lock_version' => 'integer'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
