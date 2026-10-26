<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;
use App\Services\Crm\CrmClock;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Servicio de proveedor (componente operativo, spec 02 §3.5 y §4.7, PROC-013). */
class SupplierService extends TenantModel
{
    use OptimisticLock, SoftDeletes;

    public const TYPES = ['Traslado', 'Transporte', 'Tren', 'Entrada', 'Guía', 'Alimentación', 'Alojamiento', 'Actividad', 'Tour', 'Seguro', 'Otro'];

    public const UNITS = ['pax', 'servicio', 'habitación', 'noche', 'vehículo', 'unidad'];

    public function effectiveStatus(): string
    {
        return $this->status !== 'inactivo' && $this->valid_to->toDateString() < app(CrmClock::class)->today()->toDateString() ? 'vencido' : $this->status;
    }

    /** Seleccionable como componente: activo, vigente y con proveedor activo (PRD-BR-003/007, CAT-BR-10). */
    public function isSelectable(): bool
    {
        return $this->effectiveStatus() === 'activo' && $this->provider?->isUsable();
    }

    protected $table = 'catalog_services';

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'capacity' => 'integer',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'provider_id');
    }
}
