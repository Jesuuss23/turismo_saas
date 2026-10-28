<?php

namespace App\Models\Experience;

use App\Models\Bookings\Reservation;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mensaje al pasajero programado, enviado o recibido (spec 17 §3.5). */
class PaxMessage extends TenantModel
{
    protected $table = 'notif_pax_messages';

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'read_at' => 'datetime', 'attempts' => 'integer'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PaxMessageTemplate::class, 'template_id');
    }
}
