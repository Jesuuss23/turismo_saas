<?php

namespace App\Models\Ops;

use App\Models\Bookings\BookedService;
use App\Models\Catalog\Provider;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Historial de solicitudes a proveedor por servicio (§3.4, PROC-032). */
class SupplierRequest extends TenantModel
{
    use SoftDeletes;

    protected $table = 'ops_supplier_requests';

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'responded_at' => 'datetime'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(BookedService::class, 'reservation_service_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'provider_id');
    }
}
