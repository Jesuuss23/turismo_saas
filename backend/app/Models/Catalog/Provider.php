<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;
use App\Services\Crm\CrmClock;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Spec 02 §3.4 y §4.6 (PROC-012). */
class Provider extends TenantModel
{
    use OptimisticLock, SoftDeletes;

    public const ACTIVE = 'activo';

    public const INACTIVE = 'inactivo';

    public const EXPIRED = 'vencido';

    public const TYPES = ['Hotel', 'Transporte', 'Tren', 'Guía', 'Conductor', 'Restaurante', 'Operador', 'Actividad', 'Entrada', 'Otros'];

    /** Estado efectivo: un contrato vencido vuelve "vencido" aunque el job diario no haya corrido. */
    public function effectiveStatus(): string
    {
        return $this->status === self::ACTIVE && $this->contract_end->toDateString() < app(CrmClock::class)->today()->toDateString() ? self::EXPIRED : $this->status;
    }

    /** Usable en componentes, salidas y como proveedor principal (PRD-BR-007). */
    public function isUsable(): bool
    {
        return $this->effectiveStatus() === self::ACTIVE;
    }

    protected $table = 'catalog_providers';

    protected function casts(): array
    {
        return [
            'contract_start' => 'date',
            'contract_end' => 'date',
            'rating' => 'decimal:1',
            'stars' => 'integer',
        ];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(ProviderContact::class, 'provider_id');
    }

    public function services(): HasMany
    {
        return $this->hasMany(SupplierService::class, 'provider_id');
    }
}
