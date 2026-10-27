<?php

namespace App\Models\Channel;

use App\Models\Bookings\Reservation;
use App\Models\Crm\Client;
use App\Models\Iam\User;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Solicitud creada desde un portal (spec 08 §3.12). */
class SupportTicket extends TenantModel
{
    public const CLIENT_CATEGORIES = ['Cambio de fecha', 'Documentos', 'Facturación', 'Pagos', 'Cotizaciones', 'Reclamo', 'Consulta general'];

    public const PAX_TOPICS = ['Cambio de horario', 'Recojo en hotel', 'Salud', 'Objetos perdidos', 'Otro'];

    public const STATUS_LABELS = ['abierta' => 'Abierta', 'en_proceso' => 'En proceso', 'resuelta' => 'Resuelta', 'cerrada' => 'Cerrada'];

    protected $table = 'channel_support_tickets';

    protected function casts(): array
    {
        return ['first_response_due_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id')->withTrashed();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SupportTicketEvent::class, 'ticket_id')->orderBy('occurred_at');
    }
}
