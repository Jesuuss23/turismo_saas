<?php

namespace App\Services\Collab;

use App\Exceptions\ApiException;
use App\Models\Bookings\Reservation;
use App\Models\Collab\Conversation;
use App\Models\Iam\User;
use App\Services\Crm\CrmClock;
use Illuminate\Database\Eloquent\Builder;

/**
 * `ConversationPolicy` + scope (INB-BR-04): Manager/Admin todas; Operaciones asignadas + pasajeros en viaje;
 * Ventas (y el resto con inbox.view) asignadas a él o sin asignar.
 */
class InboxScope
{
    public function __construct(private readonly CrmClock $clock) {}

    public function mode(User $user): string
    {
        return match (true) {
            $user->hasAnyRole(['tenant_admin', 'manager']) => 'all',
            $user->hasRole('operations') => 'operations',
            default => 'own',
        };
    }

    public const MODE_LABELS = [
        'all' => 'Todas las conversaciones del tenant',
        'operations' => 'Asignadas a usted + pasajeros en viaje',
        'own' => 'Asignadas a usted + sin asignar',
    ];

    public function apply(Builder $query, User $user): Builder
    {
        return match ($this->mode($user)) {
            'all' => $query,
            'operations' => $query->where(fn (Builder $q) => $q->where('assignee_id', $user->id)
                ->orWhereIn('reservation_id', $this->travellingReservations())),
            default => $query->where(fn (Builder $q) => $q->where('assignee_id', $user->id)->orWhereNull('assignee_id')),
        };
    }

    /** Reserva en operación o con hoy dentro de las fechas del viaje. */
    public function travellingReservations(): Builder
    {
        $today = $this->clock->today()->toDateString();

        return Reservation::query()->select('id')->where(fn (Builder $q) => $q->where('status', Reservation::IN_OPERATION)
            ->orWhere(fn (Builder $w) => $w->whereNotIn('status', Reservation::CLOSED)->whereDate('travel_from', '<=', $today)->whereDate('travel_to', '>=', $today)));
    }

    public function visible(Conversation $conversation, User $user): bool
    {
        return $this->apply(Conversation::whereKey($conversation->id), $user)->exists();
    }

    /** Conversación ajena → 403 «fuera de su alcance» (§7 Ventas). */
    public function ensureVisible(Conversation $conversation, User $user): void
    {
        if (! $this->visible($conversation, $user)) {
            throw ApiException::forbidden('INB-BR-04', 'La conversación está fuera de su alcance (asignada a otro usuario)', ['inbox.view']);
        }
    }
}
