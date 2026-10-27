<?php

namespace App\Models\Collab;

use App\Models\Bookings\Reservation;
use App\Models\Channel\SupportTicket;
use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Iam\User;
use App\Models\Quotes\Quote;
use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Conversación de la bandeja omnicanal (spec 10 §3.6). */
class Conversation extends TenantModel
{
    use OptimisticLock;

    protected $table = 'collab_conversations';

    protected function casts(): array
    {
        return [
            'tags' => 'array', 'first_response_due_at' => 'datetime', 'first_response_at' => 'datetime', 'sla_alerted_at' => 'datetime',
            'last_inbound_at' => 'datetime', 'last_message_at' => 'datetime', 'resolved_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id')->orderBy('sent_at')->orderBy('created_at');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'quote_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }
}
