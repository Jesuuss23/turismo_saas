<?php

namespace App\Models\Inventory;

use App\Models\Iam\User;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Solicitud de ampliación de cupo al proveedor (spec 11 §3.4). */
class AllotmentRequest extends TenantModel
{
    public const STATUS_LABELS = ['solicitada' => 'Solicitada', 'aceptada' => 'Aceptada', 'rechazada' => 'Rechazada'];

    protected $table = 'catalog_allotment_requests';

    protected function casts(): array
    {
        return ['date_from' => 'date', 'date_to' => 'date', 'responded_at' => 'datetime', 'extra_qty' => 'integer'];
    }

    public function allotment(): BelongsTo
    {
        return $this->belongsTo(Allotment::class)->withTrashed();
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
