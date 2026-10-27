<?php

namespace App\Models\Ops;

use App\Models\Iam\User;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Solicitud o recordatorio de documentos enviado al cliente (spec 11 §3.9). `sent_by` NULL = job. */
class TravelDocReminder extends TenantModel
{
    protected $table = 'ops_travel_doc_reminders';

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'sent_on' => 'date', 'days_before' => 'integer', 'items_count' => 'integer'];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
