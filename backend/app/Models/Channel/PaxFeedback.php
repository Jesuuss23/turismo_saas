<?php

namespace App\Models\Channel;

use App\Models\Bookings\BookedService;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Comentario del pasajero desde el portal (spec 08 §3.16, R-PAX-FEEDBACK). */
class PaxFeedback extends TenantModel
{
    public const TYPES = ['comentario' => 'Comentario', 'sugerencia' => 'Sugerencia', 'felicitacion' => 'Felicitación', 'reclamo' => 'Reclamo', 'incidencia' => 'Incidencia'];

    public const STATUS_LABELS = ['recibido' => 'Recibido', 'en_revision' => 'En revisión', 'respondido' => 'Respondido', 'cerrado' => 'Cerrado'];

    protected $table = 'channel_pax_feedback';

    protected function casts(): array
    {
        return ['responded_at' => 'datetime'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(BookedService::class, 'reservation_service_id');
    }
}
