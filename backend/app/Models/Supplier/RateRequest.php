<?php

namespace App\Models\Supplier;

use App\Models\Catalog\Provider;
use App\Models\Catalog\SupplierService;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Solicitud de actualización de tarifa neta (spec 06 §3.4, §4.6). */
class RateRequest extends TenantModel
{
    public const PENDING = 'pendiente';

    public const REASONS = ['Ajuste por inflación / costos', 'Cambio de temporada', 'Mejora del servicio', 'Tarifa promocional', 'Otro'];

    public const STATUS_LABELS = ['pendiente' => 'Pendiente', 'aprobada' => 'Aprobada', 'rechazada' => 'Rechazada', 'retirada' => 'Retirada'];

    public const AUDIT_COLUMNS = [];

    protected $table = 'supplier_rate_requests';

    protected function casts(): array
    {
        return ['current_cost' => 'decimal:2', 'proposed_cost' => 'decimal:2', 'effective_from' => 'date', 'requested_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'provider_id')->withTrashed();
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(SupplierService::class, 'service_id')->withTrashed();
    }
}
