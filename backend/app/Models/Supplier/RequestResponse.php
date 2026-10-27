<?php

namespace App\Models\Supplier;

use App\Models\Bookings\BookedService;
use App\Models\Catalog\Provider;
use App\Models\Catalog\SupplierService;
use App\Models\Iam\User;
use App\Models\Ops\SupplierRequest;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Respuesta del proveedor a una solicitud (spec 06 §3.2): confirmación, rechazo o alternativa. */
class RequestResponse extends TenantModel
{
    public const CONFIRMATION = 'confirmacion';

    public const REJECTION = 'rechazo';

    public const ALTERNATIVE = 'alternativa';

    public const REJECT_REASONS = ['Sin disponibilidad (sold out)', 'Fecha bloqueada por mantenimiento', 'Tarifa no vigente para la fecha', 'Cupo de grupo excedido', 'Otro'];

    public const ALT_KINDS = ['Otra fecha', 'Otro tipo de habitación', 'Otro horario', 'Upgrade sin costo', 'Otro'];

    public const REVIEW_LABELS = ['pendiente' => 'Pendiente', 'aceptada' => 'Aceptada', 'rechazada' => 'Rechazada', 'expirada' => 'Expirada'];

    protected $table = 'supplier_request_responses';

    protected function casts(): array
    {
        return ['responded_at' => 'datetime', 'alt_date' => 'date', 'hold_until' => 'date', 'alt_net_cost' => 'decimal:2', 'reviewed_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(SupplierRequest::class, 'supplier_request_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(BookedService::class, 'reservation_service_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'provider_id')->withTrashed();
    }

    public function altService(): BelongsTo
    {
        return $this->belongsTo(SupplierService::class, 'alt_service_id')->withTrashed();
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by_user_id')->withTrashed();
    }
}
