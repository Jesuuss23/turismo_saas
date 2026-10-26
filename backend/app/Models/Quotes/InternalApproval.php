<?php

namespace App\Models\Quotes;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Solicitud y decisión de aprobación interna de gerencia (§3, máquina §4.2). */
class InternalApproval extends TenantModel
{
    public const PENDING = 'pendiente';

    public const APPROVED = 'aprobada';

    public const REJECTED = 'rechazada';

    public const VOIDED = 'anulada';

    protected $table = 'quote_internal_approvals';

    protected function casts(): array
    {
        return [
            'reasons' => 'array',
            'over_cap' => 'boolean',
            'is_override' => 'boolean',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(QuoteVersion::class, 'quote_version_id');
    }
}
